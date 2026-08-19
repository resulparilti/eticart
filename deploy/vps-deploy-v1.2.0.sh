#!/bin/bash
set -euo pipefail

VERSION=v1.2.0
RELEASES=/var/www/releases
CURRENT=/var/www/eticart
NEW="$RELEASES/$VERSION"
PREV="$RELEASES/v1.1.0"
BACKUPS=/var/www/backups

mkdir -p "$RELEASES" "$BACKUPS"

mysqldump eticart > "$BACKUPS/eticart-before-$VERSION-$(date +%Y%m%d%H%M).sql"

if [ ! -L "$CURRENT" ] && [ -d "$CURRENT" ]; then
  rm -rf "$PREV"
  mv "$CURRENT" "$PREV"
elif [ -L "$CURRENT" ]; then
  PREV_REAL="$(readlink -f "$CURRENT")"
  if [ -d "$PREV_REAL" ] && [ "$PREV_REAL" != "$NEW" ]; then
    PREV="$PREV_REAL"
  fi
fi

rm -rf "$NEW"
mkdir -p "$NEW"
tar -xzf /root/eticart-v1.2.0.tar.gz -C "$NEW"
tar -xzf /root/eticart-build-v1.2.0.tar.gz -C "$NEW"

if [ -f "$PREV/.env" ]; then
  cp "$PREV/.env" "$NEW/.env"
fi

if [ -d "$PREV/storage/app" ]; then
  rsync -a "$PREV/storage/app/" "$NEW/storage/app/" || true
fi

mkdir -p "$NEW/storage/framework/sessions" "$NEW/storage/framework/views" "$NEW/storage/framework/cache/data" "$NEW/storage/logs" "$NEW/storage/app/public"
grep -q '^APP_TIMEZONE=' "$NEW/.env" || echo 'APP_TIMEZONE=Europe/Istanbul' >> "$NEW/.env"
sed -i 's|^APP_TIMEZONE=.*|APP_TIMEZONE=Europe/Istanbul|' "$NEW/.env"
grep -q '^ETICART_DEPLOYMENT=' "$NEW/.env" || echo 'ETICART_DEPLOYMENT=vps' >> "$NEW/.env"
sed -i 's|^ETICART_DEPLOYMENT=.*|ETICART_DEPLOYMENT=vps|' "$NEW/.env"
grep -q '^SCHEDULE_CRON_MINUTES=' "$NEW/.env" || echo 'SCHEDULE_CRON_MINUTES=1' >> "$NEW/.env"
sed -i 's|^SCHEDULE_CRON_MINUTES=.*|SCHEDULE_CRON_MINUTES=1|' "$NEW/.env"

cd "$NEW"
if ! php -r "exit(extension_loaded('soap') ? 0 : 1);"; then
  apt-get update
  apt-get install -y php8.2-soap
fi
composer install --no-dev --optimize-autoloader --no-interaction

mysql -e "DROP DATABASE IF EXISTS eticart; CREATE DATABASE eticart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql eticart < /root/eticart-v1.2.0.sql
mysql -e "GRANT ALL PRIVILEGES ON eticart.* TO 'eticart'@'localhost'; FLUSH PRIVILEGES;"

php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "$VERSION" > "$NEW/VERSION"
chown -R www-data:www-data "$NEW"
chmod -R ug+rwx "$NEW/storage" "$NEW/bootstrap/cache"

ln -sfn "$NEW" "$CURRENT"

if [ -f "$NEW/deploy/install-vps-cron.sh" ]; then
  bash "$NEW/deploy/install-vps-cron.sh" "$CURRENT" || true
fi

supervisorctl restart all || true
systemctl reload php8.2-fpm || true
systemctl reload nginx || true

echo "DEPLOYED $VERSION"
ls -ld "$CURRENT"
readlink -f "$CURRENT"
ls -1 "$RELEASES"
