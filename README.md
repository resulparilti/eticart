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

Cron (her dakika):

```bash
* * * * * cd /var/www/eticart && php artisan schedule:run >> /dev/null 2>&1
```

## Güvenlik

- `.env` dosyasını asla commit etmeyin.
- API anahtarları panel **Ayarlar** ekranından veritabanına yazılır.
- Kargo şifreli alanlar `APP_KEY` ile korunur; sunucu taşırken aynı `APP_KEY` kullanın.

## Lisans

Proprietary — PariltiSoft / EtiCart.
