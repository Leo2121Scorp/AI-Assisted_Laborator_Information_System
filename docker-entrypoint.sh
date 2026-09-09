#!/bin/bash
set -e
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

# Persist container env for PHP (Apache often strips getenv for workers)
ENV_PHP="/var/www/html/config/env.php"
{
  echo '<?php'
  echo 'return ['
  for key in DATABASE_URL MYSQL_URL MYSQLHOST MYSQLPORT MYSQLUSER MYSQLPASSWORD MYSQLDATABASE \
             DB_HOST DB_PORT DB_USER DB_PASSWORD DB_NAME \
             PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE \
             AI_SERVICE_URL AI_ENDPOINT AI_HEALTH_ENDPOINT APP_BASE_URL \
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
PassEnv RENDER
EOF
fi

if [ -n "${DATABASE_URL:-}" ] || [ -n "${PGHOST:-}" ]; then
  php /var/www/html/scripts/auto_install.php || true
else
  echo "WARNING: DATABASE_URL/PGHOST not set — set Internal Database URL on ailab-web" >&2
fi

exec apache2-foreground
