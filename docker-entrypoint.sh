#!/bin/bash
set -e
PORT="${PORT:-80}"
AI_PORT="${AI_PORT:-5001}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

# Isolation Forest sidecar — same container so it is awake with the web app
if [ "${START_EMBEDDED_AI:-1}" != "0" ]; then
  export HOST=127.0.0.1
  (
    cd /var/www/html/ai
    exec python3 -m gunicorn -b "127.0.0.1:${AI_PORT}" --workers 1 --threads 2 --timeout 60 app:app
  ) >/tmp/ailab-ai.log 2>&1 &
  export AI_SERVICE_URL="http://127.0.0.1:${AI_PORT}"
fi

# Persist container env for PHP (Apache often strips getenv for workers)
ENV_PHP="/var/www/html/config/env.php"
{
  echo '<?php'
  echo 'return ['
  for key in DATABASE_URL MYSQL_URL MYSQLHOST MYSQLPORT MYSQLUSER MYSQLPASSWORD MYSQLDATABASE \
             DB_HOST DB_PORT DB_USER DB_PASSWORD DB_NAME \
             PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE \
             AI_SERVICE_URL AI_ENDPOINT AI_HEALTH_ENDPOINT AI_CHAT_ENDPOINT APP_BASE_URL \
             OPENROUTER_API_KEY OPENROUTER_MODEL OPENROUTER_BASE_URL \
             BACKUP_AI_API_KEY BACKUP_AI_BASE_URL BACKUP_AI_MODEL \
             RENDER RAILWAY_ENVIRONMENT; do
    eval "val=\${$key-}"
    if [ -n "$val" ]; then
      # Escape for single-quoted PHP strings
      esc=$(printf '%s' "$val" | sed "s/'/\\\\'/g")
      echo "  '$key' => '$esc',"
    fi
  done
  echo '];'
} > "$ENV_PHP"
chown www-data:www-data "$ENV_PHP" 2>/dev/null || true

# Pass key vars into Apache/PHP as well
if [ -f /etc/apache2/conf-available/docker-php.conf ] || [ -d /etc/apache2/conf-enabled ]; then
  cat >/etc/apache2/conf-enabled/pass-env.conf <<'EOF'
PassEnv DATABASE_URL
PassEnv PGHOST
PassEnv PGPORT
PassEnv PGUSER
PassEnv PGPASSWORD
PassEnv PGDATABASE
PassEnv AI_SERVICE_URL
PassEnv OPENROUTER_API_KEY
PassEnv BACKUP_AI_API_KEY
PassEnv RENDER
EOF
fi

if [ -n "${DATABASE_URL:-}" ] || [ -n "${PGHOST:-}" ]; then
  php /var/www/html/scripts/auto_install.php || true
else
  echo "WARNING: DATABASE_URL/PGHOST not set — Blueprint should inject ailab-db connectionString, or set Internal Database URL on ailab-web" >&2
fi

exec apache2-foreground
