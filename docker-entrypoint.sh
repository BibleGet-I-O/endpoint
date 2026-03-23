#!/bin/bash
set -e

# Generate dbcredentials.php from environment variables if not already present
CRED_FILE="/var/www/html/dbcredentials.php"
if [ ! -f "$CRED_FILE" ] && [ -n "$DB_HOST" ]; then
    cat > "$CRED_FILE" <<EOPHP
<?php
define('SERVER', '${DB_HOST}');
define('DBPORT', ${DB_PORT:-5432});
define('DATABASE', '${DB_NAME}');
define('DBUSER', '${DB_USER}');
define('DBPASS', '${DB_PASS}');
EOPHP
fi

exec apache2-foreground "$@"
