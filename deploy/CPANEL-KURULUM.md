# cPanel Paylaşımlı Hosting Kurulumu

Composer **sunucuda gerekmez**. Paketi bilgisayarınızda hazırlayıp `vendor` dahil yükleyin.

## 1. Paketi hazırla (Windows — geliştirme bilgisayarı)

PowerShell, proje kökünde:

```powershell
.\deploy\build-cpanel-package.ps1
```

Çıktı: `deploy/dist/eticart-cpanel-YYYYMMDD.zip`

İçinde `vendor/`, `public/build/`, tüm uygulama dosyaları vardır.

## 2. Sunucuya yükle

### Önerilen dizin yapısı

```
/home/kullanici/
├── eticart/              ← ZIP içeriği (Laravel kök, public dahil)
│   ├── app/
│   ├── vendor/           ← hazır paketler
│   ├── public/build/     ← CSS/JS (npm build çıktısı)
│   ├── public/           ← web kökü
│   └── OKU-BENI.txt      ← hızlı başlangıç
```

### Extract sonrası izinler (kritik)

ZIP açıldıktan sonra klasörler bazen 644 kalır → `Permission denied`.

Tarayıcıda **ilk iş**:
```
https://panel.sizindomain.com/fix-permissions.php?run=1
```

Toplu izin aracı yoksa bu script tüm projeyi **755** yapar.

### cPanel → Domains → Document Root

Alan adınızın **Document Root** değerini şu klasöre ayarlayın:

```
/home/kullanici/eticart/public
```

(Alt klasör kullanıyorsanız: `.../eticart/public`)

### Alternatif: public_html içinde index.php

Laravel kökünü `eticart/` altında tutup `public_html` kullanmak istiyorsanız
`deploy/cpanel/public_html-index.php.example` dosyasındaki yolları düzenleyin.

## 3. Kurulum sihirbazı (Terminal gerekmez)

Tarayıcıda açın:

```
https://panel.sizindomain.com/setup.php
```

Sihirbaz otomatik yapar:
- Sunucu gereksinim kontrolü (PHP, eklentiler, izinler)
- `.env` oluşturma (formdan)
- Veritabanı yedeği varsa seed **çalışmaz** (Shopify / UyumSoft / kargo ayarları korunur)
- Yalnızca eksik migration'lar
- Önbellek ve storage link
- **Cron komutu**, **çıkış IP** gösterimi

Kurulumdan **önce** SQL yedeğini phpMyAdmin ile import edin.

Kurulum bitince **File Manager**'dan silin:
- `public/setup.php`
- `public/fix-permissions.php`
- `public/health.php`
- `public/clear-cache.php`

`.env` dosyasını elle oluşturmanıza gerek yok — sihirbaz yazar.
Shopify / API bilgilerini forma girmeyin; veritabanındadır.

Eski `.env` içindeki **APP_KEY** satırını setup'a yapıştırın. Kargo API şifreleri bu anahtarla çözülür.

### Alternatif: Terminal varsa

```bash
cd /home/kullanici/eticart
php artisan key:generate
php artisan eticart:cpanel-bootstrap --force
```

## 4. Dosya izinleri

Yazılabilir olmalı:

- `storage/` (alt klasörler dahil)
- `bootstrap/cache/`

cPanel File Manager → Permissions → `storage` ve `bootstrap/cache` → **755** veya **775**.

## 6. Cron (15 dakika — paylaşımlı hosting)

cPanel → Cron Jobs:

| Alan | Değer |
|------|--------|
| Dakika | `*/15` |
| Saat | `*` |
| Gün | `*` |
| Ay | `*` |
| Hafta | `*` |

Komut (yolları kendi sunucunuza göre düzenleyin):

```bash
cd /home/kullanici/eticart && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

PHP yolu için cPanel’de “Select PHP Version” veya `which php` kullanın.

Bu tek cron satırı her çalışmada (kuyruğa bırakmadan, `dispatchSync`):

- Shopify sipariş tarama
- UyumSoft stok tarama
- UyumSoft → Shopify ürün eşitleme
- Yurtiçi kargo durum sorgulama
- Varsa bekleyen UI kuyruk işleri

15 dakikada bir çalıştırır. İşlem bitmeden sonraki cron turu çakışmaz (`withoutOverlapping`).

## 7. Güncelleme (yeni sürüm)

1. Lokal: `.\deploy\build-cpanel-package.ps1`
2. Sunucudaki **`.env` dosyasına dokunmayın** (Extract sırasında Skip)
3. ZIP'i mevcut klasöre Extract
4. Tarayıcı (Terminal gerekmez):

```
https://panel.domain.com/fix-permissions.php?run=1
https://panel.domain.com/clear-cache.php?run=1
```

`setup.php` çalıştırmayın. Shopify / UyumSoft / kargo / mail ayarları `settings` ve `cargo_companies` tablolarında durur.

## 8. Sorun giderme

| Sorun | Çözüm |
|--------|--------|
| Permission denied (vendor/app) | `fix-permissions.php?run=1` |
| /login 500 hatası | `clear-cache.php?run=1`, `health.php` teşhis |
| Vite / manifest hatası | `public/build/` ZIP'te olmalı; layout `production-assets` kullanır |
| 500 hata | `storage/logs/laravel.log`, `APP_KEY`, izinler |
| Beyaz sayfa | `APP_DEBUG=true` geçici açın, log bakın |
| Cron çalışmıyor | PHP yolu, `cd` yolu, Cron Jobs log |
| Shopify OAuth | `APP_URL` ve `SHOPIFY_APP_URL` HTTPS ve domain ile eşleşmeli |

## Gereksinimler

- PHP **8.1+** (cPanel PHP Selector)
- MySQL 5.7+ / MariaDB
- `ext-mbstring`, `ext-openssl`, `ext-pdo_mysql`, `ext-fileinfo`, `ext-curl`
