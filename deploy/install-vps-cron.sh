#!/bin/bash
# EtiCart VPS cron kurulumu — schedule:run her dakika.
# Kullanım: sudo bash deploy/install-vps-cron.sh [/var/www/eticart]

set -euo pipefail

APP_PATH="${1:-/var/www/eticart}"
APP_PATH="$(readlink -f "$APP_PATH")"

if [ ! -f "$APP_PATH/artisan" ]; then
  echo "Hata: $APP_PATH/artisan bulunamadi."
  exit 1
fi

PHP_BIN="${PHP_BIN:-$(command -v php)}"
LOG_FILE="$APP_PATH/storage/logs/cron.log"
CRON_LINE="* * * * * cd $APP_PATH && $PHP_BIN artisan schedule:run >> $LOG_FILE 2>&1"
CRON_MARKER="# eticart-scheduler"
CRON_USER="${CRON_USER:-www-data}"

mkdir -p "$APP_PATH/storage/logs"
touch "$LOG_FILE"
chown -R www-data:www-data "$APP_PATH/storage" "$APP_PATH/bootstrap/cache" 2>/dev/null || true
chmod -R ug+rwx "$APP_PATH/storage" "$APP_PATH/bootstrap/cache" 2>/dev/null || true

# Root crontab'ta kalan eski kaydı temizle; schedule www-data olarak çalışsın
# (aksi halde laravel.log root olur ve panel 500 üretebilir).
ROOT_TMP="$(mktemp)"
crontab -l 2>/dev/null | grep -v "$CRON_MARKER" | grep -v "artisan schedule:run" > "$ROOT_TMP" || true
crontab "$ROOT_TMP" || true
rm -f "$ROOT_TMP"

TMP="$(mktemp)"
crontab -u "$CRON_USER" -l 2>/dev/null | grep -v "$CRON_MARKER" | grep -v "artisan schedule:run" > "$TMP" || true
echo "$CRON_LINE $CRON_MARKER" >> "$TMP"
crontab -u "$CRON_USER" "$TMP"
rm -f "$TMP"

echo "Cron kuruldu ($CRON_USER):"
crontab -u "$CRON_USER" -l | grep "$CRON_MARKER" || true
echo ""
echo "Kontrol: tail -f $LOG_FILE"
echo "Heartbeat: $APP_PATH/public/cron-status.php (kurulum sonrasi silinebilir)"
