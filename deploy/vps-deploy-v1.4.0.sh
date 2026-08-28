#!/bin/bash
# EtiCart v1.4.0 VPS guncellemesi — mevcut veritabani ve .env korunur.
set -euo pipefail

VERSION=v1.4.0
RELEASES=/var/www/releases
CURRENT=/var/www/eticart
NEW="$RELEASES/${VERSION}"
BACKUPS=/var/www/backups
REPO=https://github.com/resulparilti/eticart.git

mkdir -p "$RELEASES" "$BACKUPS"

if [ -L "$CURRENT" ]; then
  PREV="$(readlink -f "$CURRENT")"
else
  PREV="$CURRENT"
fi

if [ -z "$PREV" ] || [ ! -d "$PREV" ]; then
  echo "Onceki uygulama dizini bulunamadi: $CURRENT"
  exit 1
fi

if [ ! -f "$PREV/.env" ]; then
  echo "Onceki .env bulunamadi: $PREV/.env"
  exit 1
fi

mysqldump eticart > "$BACKUPS/eticart-before-$VERSION-$(date +%Y%m%d%H%M).sql"

if [ ! -L "$CURRENT" ] && [ -d "$CURRENT" ]; then
  STASH="$RELEASES/pre-$VERSION"
  rm -rf "$STASH"
  mv "$CURRENT" "$STASH"
  PREV="$STASH"
fi

if [ -d "$NEW" ] && [ "$NEW" = "$PREV" ]; then
  NEW="$RELEASES/${VERSION}-github"
fi

if [ -d "$NEW" ] && [ "$NEW" = "$PREV" ]; then
  echo "Canli surumun uzerine yazilamaz"
  exit 1
fi

rm -rf "$NEW"
git clone --branch "$VERSION" --depth 1 "$REPO" "$NEW"
cp "$PREV/.env" "$NEW/.env"

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

if [ -f /root/eticart-build-v1.4.0.tgz ]; then
  mkdir -p "$NEW/public"
  tar -xzf /root/eticart-build-v1.4.0.tgz -C "$NEW/public"
fi

if [ -d "$PREV/vendor" ]; then
  rsync -a "$PREV/vendor/" "$NEW/vendor/" || true
fi

cd "$NEW"
composer install --no-dev --optimize-autoloader --no-interaction

php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
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

echo "DEPLOYED $VERSION"
ls -ld "$CURRENT"
readlink -f "$CURRENT"
php artisan --version
php artisan migrate:status | tail -n 20
