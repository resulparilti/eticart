# EtiCart

Shopify, UyumSoft ve kargo entegrasyonu için Laravel tabanlı yönetim paneli.

## Gereksinimler

- PHP 8.1+
- MySQL 8 / MariaDB
- Composer
- Node.js 18+ (frontend build için)

## Kurulum (geliştirme)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Yerel otomatik senkronizasyon (ayrı terminal):

```powershell
.\scripts\start-scheduler.ps1
# veya
composer scheduler
```

Bu komut `schedule:work` çalıştırır; paneldeki sipariş/stok/ürün/kargo aralıkları otomatik uygulanır.
İşlem geçmişi ve `storage/logs/cron.log` dosyasına yazılır.

## cPanel paketi

```powershell
.\deploy\build-cpanel-package.ps1
```

## VPS dağıtım (özet)

```bash
git clone https://github.com/KULLANICI/eticart.git /var/www/eticart
cd /var/www/eticart
composer install --no-dev --optimize-autoloader
cp .env.example .env   # veya mevcut .env
php artisan key:generate # veya eski APP_KEY
php artisan migrate --force
npm ci && npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Cron (VPS — her dakika):

```bash
sudo bash deploy/install-vps-cron.sh /var/www/eticart
```

Manuel kurulum:

```bash
* * * * * cd /var/www/eticart && php artisan schedule:run >> /var/www/eticart/storage/logs/cron.log 2>&1
```

`.env` içinde `ETICART_DEPLOYMENT=vps` ve `SCHEDULE_CRON_MINUTES=1` olmalı.

## Güvenlik

- `.env` dosyasını asla commit etmeyin.
- API anahtarları panel **Ayarlar** ekranından veritabanına yazılır.
- Kargo şifreli alanlar `APP_KEY` ile korunur; sunucu taşırken aynı `APP_KEY` kullanın.

## Lisans

Proprietary — PariltiSoft / EtiCart.
