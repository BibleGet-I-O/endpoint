# API keys and plugin installations (v4)

**Status:** approaches chosen 2026-11-17; design for review.
**Issue:** [#97](https://github.com/BibleGet-I-O/endpoint/issues/97) (phases 1–2, reshaped; this document supersedes `docs/plans/identity-rbac-and-fine-grained-authz.md` where they differ).
**Program:** the first of three projects that gate the v4 release (below).

## Why

Today anyone can call the API, and the only defence is a rate limit keyed on the caller's IP, which needs IP addresses and their geolocation (IPinfo) stored in `requests_log__YYYY`. That says little about who uses the API and why, gives no way to reach them before a breaking change, and keeps personal data the project would rather not hold (#66).

From v4, every request is attributable to a **registered developer project** or a **registered installation of a BibleGet plugin**. That gives:

- who uses the API and for what (projects are described and approved);
- how many installations each plugin has, per version, and how many are active;
- a way to contact developers (verified email) about breaking changes and releases;
- revocation of one abuser without harming anyone else;
- no IP-based rate limiting and no IP or geolocation data stored.

## The v4 release program

v4 ships when all of these work together on the staging deployment (`/dev`), then go live together:

1. **This project:** keys, installations and their admin routes in the endpoint.
2. **The new frontend** (Next.js, separate design): developer pages (sign-up, projects, keys), API docs, edition reader, chapter comparison, blog (headless WordPress). The indices of persons, places and events come in a later release.
3. **The plugins** (WordPress, Google Docs, LibreOffice, OpenOffice, MS Word, Word for the web), updated to v4 and tested against `/dev`. None is published before v4 is released.

v3 stays up and open (MariaDB, IP limits, as today) until clients have moved. Its request log stays separate (per-version logs, decided 2026-11-17; #116 stays open).

## Goals

- Three credentials, all read-only in this project:
  - **Project key** — secret, issued to an approved developer project.
  - **Client key** — bundled in a published plugin, one per plugin release; not secret, only able to register installations.
  - **Installation token** — secret, issued to one installation of a plugin when it registers with its client key; used on every request after that.
- Every v4 data route (`/quote`, `/search/*`, `/metadata/*`) requires a project key or an installation token; anything else gets `401` (RFC 9457 problem+json, with a link to the developer pages).
- Per-credential rate limits and revocation.
- Telemetry: installations per plugin and version (first seen, last seen, active), requests per project or installation, without IPs.
- A first-party key for the frontend with a `reader` scope: whole chapters, for the editions whose rights allow it.
- Admin routes for approving projects, minting client keys and revoking any credential, used by the frontend.

## Non-goals

- Write access, curator workflows, OpenFGA (later phases of #97).
- Pricing tiers or billing.
- The frontend itself and the plugin changes (projects 2 and 3). This document only fixes the contract they use.
- Changes to v3.

## Anti-goals (a failure even if it works)

- Storing IP addresses or geolocation for v4 requests.
- Any credential that lets a third party get more copyrighted text than an anonymous v3 caller can (30 verses per request), other than the first-party reader key.
- An outage of the login server (Zitadel) stopping quote requests: validating a key or token must need only the endpoint's own database.

## Constraints

- PostgreSQL, plain SQL migrations in `migrations/` (next free number, today 048), PHP 8.4, PSR-15 middleware as in `src/Http/`.
- Identity: the shared Zitadel instance at `auth.catholicdigitalcommons.org`, organization `BibleGet` (pre-provisioned, unused). Login names are email addresses. A handoff note `cdcf-infra/auth/handoffs/bibleget.md` records the non-secret values.
- Reference implementation: LiturgicalCalendarAPI (`ApiKeyRepository`, `ApiKeyMiddleware`, `ApiKeyRateLimitMiddleware`, `ApplicationsHandler`, `Admin/ApplicationAdminHandler`, `OidcAuthMiddleware`, `CookieHelper`). Mirror its names and shapes where they fit; its keys are optional and it has no installations, so those parts are new here.
- Little disk on the server (about 24 GB free): telemetry is counters and last-seen timestamps, not per-request rows beyond the existing request log.

## Approaches considered

**Where keys live.**
- **A (chosen): in the endpoint.** The API owns applications, keys, client keys and installations, validates on every request and exposes `/admin/...` routes behind Zitadel login; the frontend's developer pages call them. One source of truth, same pattern as LitCal, no runtime dependency on the website.
- B: in the frontend's own database, with the API checking against it. Splits ownership, and the API would depend on the website.

**How the reader gets whole chapters.**
- **A (chosen): a first-party key with a `reader` scope.** The frontend calls the API like any client; the API decides which editions may be served whole, so copyright rules stay in one place. The comparison view uses the same path.
- B: the frontend reads PostgreSQL directly — bypasses every API rule and puts DB credentials in the frontend.
- C: pre-generated static pages — rebuilt on every data fix, and disk-hungry.

**Plugins.** Bundled keys cannot be secret (an `.oxt` is a zip, .NET decompiles, browser JS is visible; only Apps Script hides its source). Options weighed: a per-key limit with revocation only; a short-lived per-IP cap on client keys; **installation registration (chosen)** — each installation gets its own token, budget and revocation, and the counts are the telemetry wanted. WordPress uses the same model (decided 2026-11-17) instead of per-site sign-up: less friction, and its token stays on the site's server.

## Design

### Credentials

| | Project key | Client key | Installation token |
|---|---|---|---|
| Holder | approved developer project | a plugin release (bundled) | one installation of a plugin |
| Secret? | yes | no | yes |
| Can call data routes | yes | **no** | yes |
| Can register installations | no | yes | no |
| Shown | once, at creation | in the plugin's source | once, in the registration response |
| Stored | SHA-256 hash + prefix | SHA-256 hash + prefix | SHA-256 hash + prefix |

Formats follow LitCal (`litcal_{live|test}_…`): `bibleget_{live|test}_…` for project keys, `bgclient_{live|test}_…` for client keys, `bginst_{live|test}_…` for installation tokens, so a leaked string is recognisable. `live` when `APP_ENV=production`. All sent in the `X-Api-Key` header; there is no query-string form in v4.

### Data model (migration 048)

- `applications` — as LitCal: `id` UUID, `zitadel_user_id`, `name`, `description`, `website`, `status` (`pending|approved|rejected|revoked`), `review_notes`, `reviewed_by`, `reviewed_at`, `is_system`, timestamps. A project is one application.
- `api_keys` — as LitCal, with `scope` CHECK `read|reader` (`reader` only on `is_system` applications) and `rate_limit_per_hour`.
- `client_keys` — `id`, `plugin` (CHECK one of `wordpress|google-docs|libreoffice|openoffice|msword|webword`), `plugin_version`, `key_hash`, `key_prefix`, `is_active`, `created_at`, `revoked_at`, `max_registrations_per_hour`.
- `installations` — `id` UUID, `client_key_id` FK, `install_id` (UUID the plugin generates once and keeps), `token_hash`, `token_prefix`, `plugin_version` (reported; may be newer than the key's when a plugin keeps an old key), `site_url` (WordPress only, nullable), `contact_email` (optional, nullable), `is_active`, `first_seen_at`, `last_seen_at`, `request_count`. Unique on (`client_key_id`, `install_id`).
- `versions_available.reader_full_text` BOOLEAN NOT NULL DEFAULT FALSE — which editions the `reader` scope may serve whole. All false until the rights holders' permissions are recorded; public-domain editions (e.g. VGCL, DRB, LUZZI) can be set true by the admin.
- `usage_daily` — (`day`, `credential_type`, `credential_id`, `route`) primary key, `requests` count; upserted per request (every data route, not only `/quote`). This feeds the statistics; it is a few thousand small rows a day at most.
- v4 request log: the per-year `requests_log__YYYY` tables keep their role but v4 writes `credential_type` (`project|installation`) and `credential_id` instead of `WHO_IP`, `WHO_WHERE_JSON` and the `HTTP_*`/`REMOTE_ADDR` columns (those stay NULL). The IPinfo lookup (`QueryExecutor::getGeoIPInfoFromLogsElseOnline`) and the IP rate limiter are removed from v4.

### Installation registration

`POST /installations` with header `X-Api-Key: bgclient_…` and JSON `{ "install_id": "<uuid>", "plugin_version": "…", "site_url": "…"? }`:

- Unknown, revoked or inactive client key → `401`.
- New (`client_key_id`, `install_id`) → create, return `201` with `{ "token": "bginst_…", "installation_id": "…" }`.
- Existing pair → issue a new token, invalidate the old one, return `200` (a plugin that lost its token re-registers with the same `install_id`, so the count does not grow).
- Revoking a **client key** stops new registrations with it; installations already registered keep working (each is revocable on its own, and the admin can bulk-revoke the installations a client key registered in a time range). Revoking an **installation** stops only that installation.
- Registrations are capped per client key per hour (`max_registrations_per_hour`, default 200); over the cap → `429` with `Retry-After`. This is the only route reachable without a secret, so it is the only one throttled globally.

`PATCH /installations/self` (installation token) updates `plugin_version`, `site_url`, `contact_email` (WordPress settings page: "Email me about BibleGet updates"; empty string removes it).

### Request handling

Pipeline for data routes: `ErrorHandling → Logging → Credential → RateLimit → Handler`.

- `CredentialMiddleware` reads `X-Api-Key`, recognises the prefix, hashes, looks up one row (joined to its application or client key), rejects inactive/revoked/expired/unapproved → `401`; client keys on data routes → `403` ("client keys can only register installations"). Attaches `credential` (`type`, `id`, `scope`, `rate_limit_per_hour`, `application_id`/`installation_id`, `plugin`). `last_seen_at` / `last_used_at` and `request_count` are updated at most once a minute per credential, not on every request.
- With `REQUIRE_CREDENTIALS=false` (staging only, until rollout step 4) a request without a key is served without a rate limit and logged with no credential and no IP; production always runs with `true`. The IP limiter and the IPinfo code are deleted, not kept behind the flag.
- `RateLimitMiddleware` — sliding one-hour window per credential, mirroring LitCal's `RateLimiter` (file per identifier, `flock`), headers `X-RateLimit-Limit/Remaining/Reset`, `429` with `Retry-After`. Defaults: project keys 1,000/hour, installation tokens 300/hour, the system reader key exempt. Admins can change a key's or installation's limit.
- The 30-verse limit for copyrighted editions stays for every credential except `reader` scope on an edition with `reader_full_text = true`. The `forceversion`/`forcecopyright` request parameters are removed from v4 (the `reader` key replaces them).
- `appid`, `domain`, `pluginversion` query parameters are ignored by v4 (plugin and version come from the credential).
- Unrouted/health/`openapi.json` stay open.

### Statistics

`GET /admin/stats` reads `usage_daily` and `installations`: installations per plugin and version (total, active in the last 30 days by `last_seen_at`), requests per project and per plugin per day and route.

### Developer and admin routes

The **frontend runs the login flow** (Next.js with next-auth and Zitadel, as `CatholicOS/martyrology-frontend` does) and calls these routes server-side with `Authorization: Bearer <access token>`. The endpoint only validates the JWT against Zitadel's JWKS (issuer, audience = the BibleGet project, expiry) and reads roles from Zitadel's project-roles claim; it has no `/auth/*` routes, no cookies and no CORS-with-credentials, so no CSRF surface. Validation needs Zitadel's JWKS, which is fine because only the developer pages depend on it, not quote requests.

- Any signed-in user with a verified email may create projects; there is no separate "developer access request" step (approval is per project). Roles in the `BibleGet` Zitadel project: `bibleget_admin` only.
- Developer: `GET|POST /applications`, `GET|PATCH|DELETE /applications/{id}`, `POST /applications/{id}/resubmit`, `GET|POST /applications/{id}/keys`, `DELETE /applications/{id}/keys/{keyId}`, `POST /applications/{id}/keys/{keyId}/rotate` — as LitCal; keys only for approved projects; scope always `read`.
- Admin (`bibleget_admin`): approve/reject/revoke applications (as LitCal; revoking also deactivates the project's keys), `GET|POST /admin/client-keys` (mint one per plugin release; the plaintext is shown once and goes into that release's build), `POST /admin/client-keys/{id}/revoke`, `GET /admin/installations` (filter by plugin, version, active since), `POST /admin/installations/{id}/revoke`, `PATCH` limits, `GET /admin/stats` (see Statistics).
- The system reader key is minted by a script (`scripts/mint-reader-key.php`, as LitCal's `mint-official-key.php`), not by a route.
- Developers learn of approval in the developer pages (status on the project, as LitCal). Approval/rejection emails: see "Left to the builder".

### Where v4 runs

Production v4 is `https://query.bibleget.io/v4/` (the deploy workflow puts a `v4.x` release tag in `httpdocs/query/v4`); v3 stays in its own directory, deployed by hand, untouched by the merge of `development` into `master`. The `401` link, `openapi.json` `servers` and the plugins' base URL use `/v4/`; plugin dev builds use `/dev/`.

### Rollout

1. Migration 048 and the code land on `development` with `REQUIRE_CREDENTIALS=false`: credentials are recognised, logged and limited when present, unkeyed requests still served (so the staging Live suite and the frontend can be built against it).
2. Zitadel `BibleGet` project, app and `bibleget_admin` role provisioned; handoff note written; staging `.env.staging` gets the Zitadel values.
3. Dev client keys minted for each plugin; plugins and frontend tested against `/dev`.
4. `REQUIRE_CREDENTIALS=true` on staging; the Live suite runs with a test project key.
5. At release: production database, `.env.production` with `REQUIRE_CREDENTIALS=true`, live client keys minted for the plugin releases, reader key minted.

### Testing

- Unit: key generation/format/hash, prefix routing, scope rules, the copyright-limit decision table (credential × scope × `reader_full_text`).
- Integration (PostgreSQL): migration 048 on a fresh and an existing schema; registration (new, re-register, revoked client key, cap); validation of each credential state; `last_seen` throttling; rate-limit window.
- HTTP server tests: `401`/`403`/`429` bodies and headers; a data route with each credential; client key refused on data routes; `REQUIRE_CREDENTIALS=false` behaviour; admin routes with a stub OIDC token.
- Live suite (staging): runs with a project key from the environment.

## Left to the builder

- Exact names of middleware/classes and how much of LitCal's code to copy versus adapt.
- Rate-limit file location (under `logs/` or a configured path) and pruning.
- How `credential_id` is stored in the per-year log tables (new columns vs. reusing `APP_ID`), as long as no IP is written.
- Whether approval/rejection sends an email (Zitadel has the address; no mail service is wired into the endpoint today). Default: no email in this project; status shown in the developer pages.
