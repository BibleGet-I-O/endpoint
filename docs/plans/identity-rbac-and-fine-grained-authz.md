# Plan: Identity, RBAC, API Keys, and Per-Version Fine-Grained Authorization

**Status**: Proposed
**Date**: 2026-05-05
**Related Issue**: [#97](https://github.com/BibleGet-I-O/endpoint/issues/97)
**Reference implementation**: [`LiturgicalCalendar/LiturgicalCalendarAPI`](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI) — sister project that has already adopted Zitadel + API keys + OpenFGA. We mirror its patterns for architectural consistency across the two APIs.

## Motivation

The endpoint is currently fully open: no authentication, no authorization, no API keys. The only "registration" is the unenforced `appid` query parameter, used as logging metadata. As long as the API stayed read-only, this was acceptable — but the next wave of work (curator workflows for verse text corrections, per-version copyright handling, embedding re-computation triggers) introduces writes against per-version data, which need:

1. **An identity for who issued the change** — there is currently no `users` table, no session concept, no IdP trust anchor.
2. **Authorization that's resource-aware** — a curator licensed to edit `NABRE` must not be able to edit `NVBSE` or `NJB`. A coarse "is-curator" role isn't enough.
3. **Developer API keys** — for read-side abuse mitigation, per-app rate limit tiers, and (eventually) write-on-behalf-of-app flows for automated curation.

Both other constraints (the existing IP-based rate limiter in `QueryExecutor`, the GDPR review tracked in #66) are anonymous-traffic concerns — orthogonal to this plan, not replaced by it.

## Reference: how LiturgicalCalendarAPI does it

The sister project's middleware pipeline runs:

```
HttpsEnforcement → OidcAuth (with JWT fallback) → ApiKey → Authorization (role) → OpenFgaAuthorization (FGA tuple) → Handler
```

Key files to read before implementing:

| Component | LCA path |
|---|---|
| OIDC middleware (Zitadel) | `src/Http/Middleware/OidcAuthMiddleware.php` |
| API key middleware | `src/Http/Middleware/ApiKeyMiddleware.php` |
| Role-based middleware | `src/Http/Middleware/AuthorizationMiddleware.php` |
| OpenFGA middleware | `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` |
| OpenFGA client | `src/Services/OpenFgaClient.php` |
| API-key repository | `src/Repositories/ApiKeyRepository.php` |
| OpenFGA model | `scripts/openfga-model.json` |
| Router wiring | `src/Router.php` (look for `forCalendarEditor()`, `forCalendarData()`) |
| Docker services | `docker-compose.yml` (services `zitadel`, `openfga`, `openfga-migrate`) |

We adopt the same PSR-15 request attribute names (`oidc_user`, `oidc_token`, `api_key`) so any cross-project shared utilities can stay shared.

## Auth Surface — what gets gated

| Surface | Auth requirement |
|---|---|
| `GET /v3/quote`, `/v3/search/*`, `/v3/metadata/*` | Open. API key optional (enhances rate limit / tags requests). |
| `POST/PUT/PATCH/DELETE /v3/admin/versions/{sigla}/...` *(new)* | OIDC user with `bibleget_curator` role **and** FGA `editor` (or `admin`/`deleter`) on `bible_version:{SIGLA}`. |
| `POST/PUT/PATCH/DELETE /v3/admin/versions` *(new — version lifecycle)* | OIDC user with `bibleget_admin` role. No per-version FGA needed (operating on the collection). |
| `POST /v3/admin/embeddings/recompute` *(new)* | OIDC user with `bibleget_admin` role. Or a service-account API key with `write` scope. |
| `GET/POST /v3/admin/api-keys` *(new — self-service portal)* | OIDC user. Operates on keys owned by the authenticated user only. |

Everything currently in `src/Handlers/` stays open — adding auth must not break the WordPress plugin, the Apps Script add-on, or the MediaWiki extension. New surface goes under `/v3/admin/`.

## Middleware Pipeline

`Router.php` already builds a `MiddlewarePipeline` per request. We add new middleware layers that mirror LCA's order:

```
ErrorHandlingMiddleware  ← outermost (existing)
LoggingMiddleware        ← existing
HttpsEnforcementMiddleware  ← new, only on /v3/admin/* in production
OidcAuthMiddleware       ← new (only required on /v3/admin/*; pass-through elsewhere if no token)
ApiKeyMiddleware         ← new (always optional; attaches `api_key` attribute if present)
RoleAuthorizationMiddleware  ← new (per-route, e.g. RoleAuthorizationMiddleware::forCurator())
OpenFgaAuthorizationMiddleware  ← new (per-route, e.g. ::forBibleVersion())
Handler                  ← existing
```

Both `OidcAuthMiddleware` and `ApiKeyMiddleware` should be **conditionally enabled** by env vars (`ZITADEL_ISSUER`, `OPENFGA_API_URL`). When unset, those middleware become no-ops — making local dev viable without spinning up the full stack and giving us a clean rollback story.

## Phase 1 — Zitadel OIDC Authentication

**Goal**: Add OIDC-based identity for human users (curators, admins) using Zitadel as the IdP.

### Dependencies

- `firebase/php-jwt` (already a transitive dep via Monolog? — verify; otherwise `composer require`)
- `psr/cache` + a PSR-6 filesystem cache implementation (for JWKS caching)

### Changes

1. **Add `OidcAuthMiddleware`** at `src/Http/Middleware/OidcAuthMiddleware.php`
   - Port the LCA implementation; same env var names (`ZITADEL_ISSUER`, `ZITADEL_CLIENT_ID`, `ZITADEL_PROJECT_ID`, optional `ZITADEL_INTERNAL_URL` for Docker).
   - JWKS cached via `Firebase\JWT\CachedKeySet` at `cache/jwks/` (TTL 3600 s, configurable).
   - On valid token, attach `oidc_user` (assoc array with `sub`, `email`, `name`, `roles`, `project_id`) and `oidc_token` (raw payload) as PSR-15 request attributes.
   - On invalid token, throw `UnauthorizedException` (already exists in `src/Http/Exception/`).
   - On missing token + non-required route, pass through.

2. **Roles claim parsing**
   - Zitadel emits roles under `urn:zitadel:iam:org:project:roles` as an object whose keys are role names.
   - Reserved BibleGet role names: `bibleget_admin`, `bibleget_curator`, `bibleget_developer`.
   - Match LCA's fallback: if the JWT lacks the roles claim (e.g., M2M JWT-Profile tokens), call Zitadel Management API `/management/v1/users/grants/_search` with a 5-min cache.

3. **Local dev**
   - `docker-compose.yml` gains a `zitadel` service (v1, with embedded init DB + Login V2). Mirror LCA's compose stanza.
   - Pre-seeded admin user for fixture tests.

### Testing

- Unit: token-validation happy path + each failure mode (expired, wrong audience, wrong issuer, malformed).
- Integration: real Zitadel container in CI (LCA does this; same approach).
- Verify pass-through behavior when `ZITADEL_ISSUER` is unset — open endpoints stay open in local dev.

## Phase 2 — API Keys for Developers

**Goal**: Issue per-application API keys for read-side rate-limit tiering and (eventually) write-on-behalf-of-app workflows. **Replaces the unenforced `appid` parameter.**

### Schema

Mirror LCA's `api_keys` and `applications` tables. Migration `migrations/00X-add-applications-and-api-keys.sql`:

```sql
CREATE TABLE applications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_zitadel_sub TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    status TEXT NOT NULL DEFAULT 'pending',  -- pending | approved | suspended
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE api_keys (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    application_id UUID NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    key_hash CHAR(64) NOT NULL,           -- SHA-256 hex
    key_prefix VARCHAR(20) NOT NULL,      -- first 20 chars, for UI display
    name TEXT NOT NULL,
    scope TEXT NOT NULL DEFAULT 'read',   -- read | write
    rate_limit_per_hour INTEGER,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_used_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_api_keys_hash ON api_keys (key_hash);
CREATE INDEX idx_api_keys_prefix ON api_keys (key_prefix);
```

### Key format

`bibleget_{env}_{32-hex-chars}` where `env ∈ {test, live}`. Plain key returned **once** at creation; only `key_hash` (SHA-256) is persisted. Match LCA's `ApiKeyRepository::generate()`.

### Middleware

`ApiKeyMiddleware` reads `X-Api-Key` header (preferred) or `api_key` query param (deprecated, log warning). Always optional — passes through if absent. On valid key, attaches `api_key` request attribute with `id`, `application_id`, `app_uuid`, `app_name`, `owner_zitadel_sub`, `scope`, `rate_limit_per_hour`. Updates `last_used_at` on every successful validation.

### Self-service portal

A new admin route `/v3/admin/applications` and `/v3/admin/api-keys` lets a Zitadel-authenticated user manage their own apps and keys. Operates only on rows where `applications.owner_zitadel_sub = oidc_user.sub`. No FGA needed — ownership is the only access rule.

### Interaction with existing rate limiter

The `QueryExecutor` rate limiter (10/30/100 thresholds, IP-based, 2-day window) stays as the anonymous floor. With a valid API key, `rate_limit_per_hour` from the key takes over. **Anonymous quota stays a separate budget from API-key quota** — this avoids API-key holders being throttled by other anonymous traffic from shared IPs (corporate NAT, etc.).

### Backward compatibility

The legacy `appid` parameter continues to be accepted for one full release cycle. Logged with a deprecation warning that includes a pointer to the registration portal. After the deprecation window, `appid` becomes a no-op.

## Phase 3 — RBAC Roles

**Goal**: Coarse role-gating before per-resource FGA checks run. Same two-layer pattern as LCA.

### Roles

| Role | Granted by | Capability |
|---|---|---|
| `bibleget_developer` | Self-service registration (auto-granted on first OIDC login that hits the portal) | Manage own `applications` / `api_keys` |
| `bibleget_curator` | Granted by `bibleget_admin` in Zitadel console | Eligible to receive per-version FGA `editor`/`deleter` tuples |
| `bibleget_admin` | Granted by Zitadel org admin | Full bypass of FGA checks; manages version lifecycle, embedding recomputation, role grants |

### Middleware

`RoleAuthorizationMiddleware` with named factory methods like LCA's:

```php
RoleAuthorizationMiddleware::forCurator()   // requires bibleget_curator OR bibleget_admin
RoleAuthorizationMiddleware::forAdmin()     // requires bibleget_admin
RoleAuthorizationMiddleware::forDeveloper() // requires any authenticated user (effectively)
```

Throws `ForbiddenException` (RFC 9457 `problem+json`) if the role is missing.

`bibleget_admin` short-circuits any subsequent FGA check — admins implicitly have all relations on all objects.

## Phase 4 — OpenFGA Fine-Grained Authorization (per-version)

**Goal**: Limit which Bible versions a curator can edit. **Scoping is per-version (`bible_version:{SIGLA}`), not per-book.** Per-book scoping was considered and rejected — it explodes tuple count by ~73× without a real curator workflow that needs that granularity, and would tangle the deuterocanonical / version-specific book inventory into the auth model.

### Object types & relations

`scripts/openfga-model.json`:

```json
{
  "schema_version": "1.1",
  "type_definitions": [
    { "type": "user" },
    {
      "type": "application",
      "relations": {
        "owner": { "this": {} }
      },
      "metadata": {
        "relations": {
          "owner": { "directly_related_user_types": [{ "type": "user" }] }
        }
      }
    },
    {
      "type": "bible_version",
      "relations": {
        "admin":   { "this": {} },
        "viewer":  { "this": {} },
        "editor":  { "this": {} },
        "deleter": { "this": {} }
      },
      "metadata": {
        "relations": {
          "admin":   { "directly_related_user_types": [{ "type": "user" }] },
          "viewer":  { "directly_related_user_types": [{ "type": "user" }] },
          "editor":  { "directly_related_user_types": [{ "type": "user" }] },
          "deleter": { "directly_related_user_types": [{ "type": "user" }] }
        }
      }
    }
  ]
}
```

Identical relation alphabet to LCA's `national_calendar` / `diocesan_calendar` types, just over a `bible_version` object instead. This makes it trivial to share authorization-flow utilities across the two APIs.

Tuple examples:

- `user:abc123` `editor` `bible_version:NABRE` — Alice can edit NABRE.
- `user:abc123` `viewer` `bible_version:NJB` — Alice can read pre-publication NJB drafts.
- `user:def456` `admin` `application:550e8400-e29b...` — Bob owns this app.

### HTTP method → relation map

Match LCA's mapping:

| Method | Required relation |
|---|---|
| `GET` *(future preview/draft endpoints)* | `viewer` |
| `POST` / `PUT` / `PATCH` | `editor` |
| `DELETE` | `deleter` |
| Lifecycle ops on the version itself (rename sigla, change copyright) | `admin` |

### Middleware

`OpenFgaAuthorizationMiddleware::forBibleVersion()` runs **after** `RoleAuthorizationMiddleware::forCurator()`. It:

1. Extracts `{sigla}` from the route path.
2. Maps the request method to a relation.
3. Calls `$openFgaClient->check("user:{$oidc_user.sub}", $relation, "bible_version:{$sigla}")`.
4. On `false`, throws `ForbiddenException`.
5. On `true`, continues to the handler.

Admin role short-circuits step 3 (return immediately).

### PHP client

Port LCA's `src/Services/OpenFgaClient.php`. PSR-18-flavored Guzzle client; methods `check()`, `writeTuple()`, `deleteTuple()`. Env vars `OPENFGA_API_URL`, `OPENFGA_STORE_ID`, `OPENFGA_MODEL_ID`, `OPENFGA_API_TOKEN`.

### Tuple management

Tuples are managed out-of-band by `bibleget_admin` users via:

- A new `/v3/admin/versions/{sigla}/curators` endpoint (admin-only) that lists/grants/revokes tuples on `bible_version:{sigla}`.
- (Stretch) An admin web UI in a future iteration; out of scope here.

### Local dev

`docker-compose.yml` gains:
- `openfga-migrate` (one-shot, schema migration)
- `openfga` (server)
- Both pointed at the same Postgres instance, separate database (`openfga` DB), so nothing competes with the Bible data DB.

## Phase 5 — Curator Write Endpoints

**Goal**: Actually expose the write surface that the auth stack gates. Out of scope to fully design here; this phase is sketched so the auth plan's contract makes sense.

Likely endpoints under `/v3/admin/versions/{sigla}/`:

- `PATCH /verses/{book}/{chapter}/{verse}` — correct verse text. Must invalidate `verse_text_hash` (already in schema) and clear the row's `embedding` so the batch job re-computes on the next run.
- `POST /verses/{book}/{chapter}/{verse}/notes` — curator notes (new table; out of scope to design here).
- `POST /reembed` — explicit re-embedding trigger (admin-only).
- `PATCH /metadata` — language, copyright, default sigla (admin-only).

All editing endpoints emit a `bibleget_curator_audit` log entry containing `oidc_user.sub`, the diff, and a timestamp. Audit log shape mirrors LCA's `AuditLogRepository`.

## Migration Strategy

The hard constraint: **existing read clients must keep working with no changes**.

1. **All current handlers stay anonymous.** OIDC and API-key middleware are pass-through when their env vars are unset (Phase 1 / 2 acceptance criteria).
2. **`appid` deprecation runs for one release cycle.** Logged warning that `appid` will become a no-op; pointer to the registration portal.
3. **Admin surface is opt-in by URL.** Nothing under `/v3/admin/*` exists today, so adding it can't break anyone.
4. **Production rollout order:**
   1. Phase 1 + Phase 2 deployed with `ZITADEL_ISSUER` *unset* in prod — code lands, behavior unchanged.
   2. Soft-launch the registration portal at a beta URL, gather first wave of `bibleget_developer` users.
   3. Set `ZITADEL_ISSUER` in prod; portal becomes live. Anonymous traffic still works.
   4. Phase 3 + Phase 4 land; admin surface opens for first curator (NABRE).
   5. Phase 5 endpoints follow.

## Open Questions

- **Where does Zitadel run?** Self-hosted alongside the API (LCA model) or use Zitadel Cloud? Self-hosting keeps everything on one VPS but adds an operational burden (Zitadel itself needs a Postgres, login UI, TLS, …). Cloud trades cost for ops simplicity. LCA self-hosts; we can default to that for symmetry.
- **API-key rate-limit tiering policy.** LCA stores `rate_limit_per_hour` per key. Do we want named tiers (free / standard / premium) at the application level, or per-key flat numbers? Tiered makes the upgrade story cleaner but adds a `tiers` table.
- **Audit log retention.** Curator edits are sensitive; retention should outlast the per-year `requests_log__YYYY` rotation. A separate `curator_audit_log` table that doesn't rotate? GDPR review (#66) probably has an opinion here.
- **Cross-project user identity.** If a user is registered as `bibleget_developer` in Zitadel and also `calendar_developer` in the LCA Zitadel project, do we want one Zitadel project shared across both APIs (single `sub` everywhere), or two separate projects? Sharing simplifies the developer experience; separate projects keep blast radii independent.
- **Bypass for the WordPress / Apps Script / MediaWiki client repos.** Each of those clients has its own `appid` value baked in. If `appid` is being deprecated, we either ship API keys to each client repo or grant them an indefinite grace period. Decision should land in the deprecation announcement.
- **Tuple management UX.** Granting `editor` to a curator on `bible_version:NABRE` is a one-line FGA write today, but doing it via a JSON API call from a CLI is friction. Is the admin web UI a hard prerequisite, or can the first curator be onboarded by a manual `fga write` from the operator's shell?
