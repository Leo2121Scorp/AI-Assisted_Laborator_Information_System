#!/bin/bash
set -e
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/" /etc/apache2/sites-available/000-default.conf

# Auto-run DB install once when DATABASE_URL is present and users table is empty/missing
if [ -n "${DATABASE_URL:-}" ] || [ -n "${PGHOST:-}" ]; then
  php /var/www/html/scripts/auto_install.php || true
fi

exec apache2-foreground
