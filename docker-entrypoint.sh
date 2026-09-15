#!/bin/bash
set -e
PORT="${PORT:-80}"
AI_PORT="${AI_PORT:-5001}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

start_embedded_ai() {
  echo "Starting Isolation Forest on 127.0.0.1:${AI_PORT}"
  cd /var/www/html/ai
  export PYTHONUNBUFFERED=1
  if python3 -c "import gunicorn" 2>/dev/null; then
    python3 -m gunicorn -b "127.0.0.1:${AI_PORT}" --workers 1 --threads 2 --timeout 60 --access-logfile - --error-logfile - app:app &
  else
    echo "gunicorn missing — falling back to flask"
    HOST=127.0.0.1 PORT="${AI_PORT}" python3 app.py &
  fi
  local i
  for i in $(seq 1 45); do
    if python3 - <<PY
import urllib.request
urllib.request.urlopen("http://127.0.0.1:${AI_PORT}/health", timeout=1).read()
PY
    then
      echo "Isolation Forest ready on 127.0.0.1:${AI_PORT}"
      return 0
    fi
    sleep 1
  done
  echo "WARNING: Isolation Forest did not respond on 127.0.0.1:${AI_PORT}" >&2
  return 1
}

# Same-container Isolation Forest so free-tier ailab-ai sleep cannot mark the dashboard offline.
if [ "${START_EMBEDDED_AI:-1}" != "0" ]; then
  start_embedded_ai || true
  export AI_SERVICE_URL="http://127.0.0.1:${AI_PORT}"
  export AI_ENDPOINT="http://127.0.0.1:${AI_PORT}/predict"
  export AI_HEALTH_ENDPOINT="http://127.0.0.1:${AI_PORT}/health"
  export START_EMBEDDED_AI=1
fi

# Persist container env for PHP (Apache often strips getenv for workers)
ENV_PHP="/var/www/html/config/env.php"
{
  echo '<?php'
  echo 'return ['
  for key in DATABASE_URL MYSQL_URL MYSQLHOST MYSQLPORT MYSQLUSER MYSQLPASSWORD MYSQLDATABASE \
             DB_HOST DB_PORT DB_USER DB_PASSWORD DB_NAME \
             PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE \
             AI_SERVICE_URL AI_ENDPOINT AI_HEALTH_ENDPOINT AI_CHAT_ENDPOINT AI_PORT START_EMBEDDED_AI APP_BASE_URL \
             GROQ_API_KEY GROQ_MODEL GROQ_BASE_URL \
             BACKUP_AI_API_KEY BACKUP_AI_BASE_URL BACKUP_AI_MODEL \
             RENDER RAILWAY_ENVIRONMENT; do
    eval "val=\${$key-}"
    if [ -n "$val" ]; then
      esc=$(printf '%s' "$val" | sed "s/'/\\\\'/g")
      echo "  '$key' => '$esc',"
    fi
  done
  echo '];'
} > "$ENV_PHP"
chown www-data:www-data "$ENV_PHP" 2>/dev/null || true

if [ -f /etc/apache2/conf-available/docker-php.conf ] || [ -d /etc/apache2/conf-enabled ]; then
  cat >/etc/apache2/conf-enabled/pass-env.conf <<EOF
PassEnv DATABASE_URL
PassEnv PGHOST
PassEnv PGPORT
PassEnv PGUSER
PassEnv PGPASSWORD
PassEnv PGDATABASE
PassEnv AI_SERVICE_URL
PassEnv AI_ENDPOINT
PassEnv AI_HEALTH_ENDPOINT
PassEnv START_EMBEDDED_AI
PassEnv GROQ_API_KEY
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
