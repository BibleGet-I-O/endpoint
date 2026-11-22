-- 060: service notices, which every data-route answer carries.
--
-- BibleGet's way to tell the clients using it what they need to know (a breaking change ahead, a release to upgrade
-- to): a registered plugin installation has no developer account to write to, so it learns from the answers
-- themselves (service_notices in JSON, in quote XML and HTML) and from the Deprecation / Sunset headers
-- (src/Services/ServiceNotices.php). Published and withdrawn by the admin (/admin/service-notices).
--
--   id                      stable slug: a client shows each notice once
--   message, link           what to say, and an optional https page with more
--   published_at/expires_at when the notice is shown
--   deprecation_at          a scheduled deprecation (RFC 9745 Deprecation header)
--   sunset_at               when something stops working (RFC 8594 Sunset header)
--   target_*                optional: one credential type, one plugin, that plugin's versions below one
--
-- Run by Doctrine (src/Migrations/Version060ServiceNotices.php): no BEGIN/COMMIT. Idempotent.

CREATE TABLE IF NOT EXISTS service_notices (
    id                     TEXT PRIMARY KEY CHECK (id ~ '^[a-z0-9][a-z0-9-]{2,63}$'),
    message                TEXT NOT NULL CHECK (char_length(message) BETWEEN 1 AND 1000),
    link                   TEXT CHECK (link IS NULL OR (link ~ '^https://' AND char_length(link) <= 500)),
    published_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at             TIMESTAMPTZ,
    deprecation_at         TIMESTAMPTZ,
    sunset_at              TIMESTAMPTZ,
    target_plugin          TEXT,
    target_below_version   TEXT,
    target_credential_type TEXT CHECK (target_credential_type IN ('project', 'installation')),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (target_below_version IS NULL OR target_plugin IS NOT NULL),
    CHECK (expires_at IS NULL OR expires_at > published_at)
);
