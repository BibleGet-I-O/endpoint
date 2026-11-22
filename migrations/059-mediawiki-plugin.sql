-- 059: the MediaWiki extension is a plugin that registers installations.
--
-- BibleGet MediaWiki extension 2.0 registers each wiki through POST /installations with a client key bundled in its
-- extension.json, like the other plugins; client_keys.plugin only allowed the six earlier ones. The constraint is
-- recreated with 'mediawiki' added (ClientKeyAdminHandler::PLUGINS lists the same names). Idempotent.
--
-- Run by Doctrine (src/Migrations/Version059MediawikiPlugin.php): no BEGIN/COMMIT.

ALTER TABLE client_keys DROP CONSTRAINT IF EXISTS client_keys_plugin_check;
ALTER TABLE client_keys ADD CONSTRAINT client_keys_plugin_check
    CHECK (plugin IN ('wordpress', 'google-docs', 'libreoffice', 'openoffice', 'msword', 'webword', 'mediawiki'));
