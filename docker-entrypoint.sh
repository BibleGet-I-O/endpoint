#!/bin/bash
set -e

# Generate .env from Docker environment variables if not already present.
# The front controller (public/index.php) loads this via phpdotenv.
ENV_FILE="/var/www/html/.env"
if [ ! -f "$ENV_FILE" ] && [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
    cat > "$ENV_FILE" <<EOF
APP_ENV=${APP_ENV:-production}
API_BASE_PATH=${API_BASE_PATH:-/v3}
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT:-5432}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF
fi

exec apache2-foreground "$@"
