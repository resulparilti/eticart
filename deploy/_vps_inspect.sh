#!/bin/bash
set -e
echo HOST="$(hostname)"
echo "---PATHS---"
ls -ld /var/www/eticart /var/www/releases 2>/dev/null || true
echo "---GIT---"
if [ -d /var/www/eticart/.git ]; then
  cd /var/www/eticart
  git rev-parse --abbrev-ref HEAD
  git log -1 --oneline
  git remote -v
  git status -sb
else
  echo "no git repo at /var/www/eticart"
  ls -la /var/www/eticart 2>/dev/null | head -20 || true
fi
echo "---ENV---"
if [ -f /var/www/eticart/.env ]; then
  grep -E '^(APP_URL|APP_ENV|DB_DATABASE|DB_USERNAME|ETICART_DEPLOYMENT)=' /var/www/eticart/.env || true
fi
echo "---PHP---"
php -v | head -1
echo "---MYSQL---"
mysql -N -e "SHOW DATABASES;" || true
echo "---DISK---"
df -h /
