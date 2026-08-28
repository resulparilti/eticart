#!/bin/bash
set -euo pipefail

VERSION=v1.3.0
RELEASES=/var/www/releases
CURRENT=/var/www/eticart
NEW="$RELEASES/$VERSION"
BACKUPS=/var/www/backups
REPO=https://github.com/resulparilti/eticart.git

mkdir -p "$RELEASES" "$BACKUPS"

PREV="$(readlink -f "$CURRENT" 2>/dev/null || true)"
if [ -z "$PREV" ] || [ ! -d "$PREV" ]; then
  PREV="$CURRENT"
fi

mysqldump eticart > "$BACKUPS/eticart-before-$VERSION-$(date +%Y%m%d%H%M).sql"

if [ -d "$NEW" ] && [ "$NEW" = "$PREV" ]; then
  echo "Refusing to replace the live release in place"
  exit 1
fi

rm -rf "$NEW"
git clone --depth 1 "$REPO" "$NEW"
cd "$NEW"
git fetch --depth 1 origin 0dd66c90f6c69c9ca0d917ac9efa9010c986e785 || true
git checkout --detach origin/main

if [ -f "$PREV/.env" ]; then
  cp "$PREV/.env" "$NEW/.env"
else
  echo "Missing previous .env"
  exit 1
fi

if [ -f /root/eticart_app_key.env ]; then
  KEY="$(tr -d '\r\n' < /root/eticart_app_key.env)"
  if [ -n "$KEY" ]; then
    if grep -q '^APP_KEY=' "$NEW/.env"; then
      sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" "$NEW/.env"
    else
      echo "APP_KEY=${KEY}" >> "$NEW/.env"
    fi
  fi
  rm -f /root/eticart_app_key.env
fi

grep -q '^APP_ENV=' "$NEW/.env" || echo 'APP_ENV=production' >> "$NEW/.env"
sed -i 's|^APP_ENV=.*|APP_ENV=production|' "$NEW/.env"
grep -q '^APP_DEBUG=' "$NEW/.env" || echo 'APP_DEBUG=false' >> "$NEW/.env"
sed -i 's|^APP_DEBUG=.*|APP_DEBUG=false|' "$NEW/.env"
grep -q '^ETICART_DEPLOYMENT=' "$NEW/.env" || echo 'ETICART_DEPLOYMENT=vps' >> "$NEW/.env"
sed -i 's|^ETICART_DEPLOYMENT=.*|ETICART_DEPLOYMENT=vps|' "$NEW/.env"
grep -q '^SCHEDULE_CRON_MINUTES=' "$NEW/.env" || echo 'SCHEDULE_CRON_MINUTES=1' >> "$NEW/.env"
sed -i 's|^SCHEDULE_CRON_MINUTES=.*|SCHEDULE_CRON_MINUTES=1|' "$NEW/.env"
grep -q '^APP_TIMEZONE=' "$NEW/.env" || echo 'APP_TIMEZONE=Europe/Istanbul' >> "$NEW/.env"
sed -i 's|^APP_TIMEZONE=.*|APP_TIMEZONE=Europe/Istanbul|' "$NEW/.env"

mkdir -p "$NEW/storage/framework/sessions" "$NEW/storage/framework/views" "$NEW/storage/framework/cache/data" "$NEW/storage/logs" "$NEW/storage/app/public"

if [ -d "$PREV/storage/app" ]; then
  rsync -a "$PREV/storage/app/" "$NEW/storage/app/" || true
fi

if [ -f /root/eticart-storage.tgz ]; then
  tar -xzf /root/eticart-storage.tgz -C "$NEW/storage/app"
fi

if [ -f /root/eticart-build.tgz ]; then
  mkdir -p "$NEW/public"
  tar -xzf /root/eticart-build.tgz -C "$NEW/public"
fi

composer install --no-dev --optimize-autoloader --no-interaction

mysql < /root/eticart-local.sql
mysql -e "CREATE DATABASE IF NOT EXISTS eticart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "GRANT ALL PRIVILEGES ON eticart.* TO 'eticart'@'localhost'; FLUSH PRIVILEGES;"

cd "$NEW"
php artisan migrate --force
php artisan storage:link --force || true
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
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

rm -f /root/eticart-local.sql /root/eticart-storage.tgz /root/eticart-build.tgz /tmp/eticart_inspect.sh

echo "DEPLOYED $VERSION"
ls -ld "$CURRENT"
readlink -f "$CURRENT"
php -r "require '$NEW/vendor/autoload.php'; echo 'ok';"
