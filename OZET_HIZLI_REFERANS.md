# 📌 EtiCart - Özet & Hızlı Referans

## 🎯 PROJE ÖZÜ

| Özellik | Değer |
|---------|-------|
| **Adı** | EtiCart - E-ticaret Entegrasyon Paneli |
| **Framework** | Laravel 11 + PHP 8.2+ |
| **Database** | MySQL 8.0+ |
| **Cache/Queue** | Redis 7.0+ |
| **Frontend** | Blade + Bootstrap 5.3 (Minimal Modern) |
| **API'ler** | Shopify, UyumSoft, Kargo (4x), Mail, SMS |
| **Geliştirme Süresi** | 40-50 saat |
| **Geliştirici Aracı** | Cursor AI |

---

## 📂 DOSYA HARITASI

| Dosya | İçerik |
|-------|--------|
| `PROJE_YOL_HARITASI.md` | Tüm özellikler, veritabanı şeması, menü, API'ler |
| `.cursorrules` | Cursor AI kuralları, kod standartları, best practices |
| `TODO.md` | 13 Fase, her fase için görev listesi |
| `01-BASLANGIC_REHBERI.md` | Kurulum, configuration, ilk çalıştırma |
| `OZET_HIZLI_REFERANS.md` | Bu dosya |

---

## 🚀 HIZLI BAŞLANGIC

### 1. Kurulumu Yap (1 saat)

```bash
# Gerekli yazılımlar: PHP 8.2, MySQL 8, Redis, Node.js, Composer

# Proje oluştur
composer create-project laravel/laravel eticart
cd eticart

# Dependencies yükle
npm install
composer install

# Environment ayarla
cp .env.example .env
php artisan key:generate

# Database oluştur ve migrate et
php artisan migrate:fresh

# Authentication kur
php artisan breeze:install blade

# Pakages yükle
composer require guzzlehttp/guzzle redis intervention/image barryvdh/laravel-dompdf spatie/laravel-permission
npm install bootstrap@5.3.0 bootstrap-icons alpinejs

# .cursorrules dosyasını kopyala
# → outputs/.cursorrules → eticart/.cursorrules
```

### 2. Server'leri Başlat (Terminal'leri aç)

```bash
# Terminal 1: Laravel
php artisan serve

# Terminal 2: Queue
php artisan queue:work

# Terminal 3: Redis
redis-server  (veya wsl redis-server, veya docker)
```

### 3. Cursor'de Başla

```bash
# Proje klasörü aç: eticart/
# Cursor chat: Ctrl + Shift + P → New Chat

# Prompt:
@workspace
FASE 2'yi başlat: Dashboard oluştur
```

---

## 📊 YAPI VE MENÜ

### Menü (Sidebar)

```
📊 Dashboard
📦 Siparişler
  ├── Tüm Siparişler
  ├── Beklemede
  ├── Kargoda
  └── Tamamlanmış

🏷️ Ürünler
  ├── UyumSoft Ürünleri
  ├── Shopify Ürünleri
  ├── Senkronizasyon
  └── Fiyat/Stok

🚚 Kargo Yönetimi
  ├── Kargo Gönderileri
  ├── Yeni Kargo
  └── Yazdırma

⚙️ Ayarlar
  ├── Shopify Bağlantısı
  ├── UyumSoft Bağlantısı
  ├── Kargo Ayarları
  ├── Mail Ayarları
  ├── SMS Ayarları
  ├── Sync Zaman Ayarları
  └── Şablonlar

👥 Kullanıcılar
📊 Raporlar
```

---

## 🗄️ VERITABANI

### Ana Tables

```
users               → Admin kullanıcıları
settings            → Tüm ayarlar (key-value)

uyumsoft_products   → UyumSoft ürün senkronizasyonu
shopify_products    → Shopify ürünleri

shopify_orders      → Shopify siparişleri
shopify_order_items → Sipariş ürünleri

shipments           → Kargo gönderileri
cargo_companies     → Kargo firmaları

sync_jobs           → Scheduled jobs
sync_job_logs       → Job execution logs

mail_templates      → Email şablonları
sms_templates       → SMS şablonları
notifications       → Gönderilen mesajlar
```

---

## 🔄 API ENTEGRASYONLARI

### Shopify

```
REST API: https://[store].myshopify.com/admin/api/2024-01/

GET /orders.json
GET /products.json
POST /products.json
PUT /products/{id}.json
POST /fulfillments.json

GraphQL: Admin GraphQL API
```

### UyumSoft

```
Base URL: https://api.uyumsoft.com/api/v1

GET /products
GET /product/{id}
GET /stocks
POST /stocks
GET /invoices
```

### Kargo Firmaları

```
Aras Kargo    → REST API
MNG Kargo     → SOAP Web Service
Yurtiçi Kargo → REST API
PTT           → REST API

Her biri için: createShipment(), getTracking(), generateLabel()
```

### Mail & SMS

```
Mail  → SMTP (Gmail, provider)
SMS   → Netgsm, İletişim.net, vb.
```

---

## ⚙️ AYARLAR SAYFASI

### Konfigüre Edilecek Ayarlar

```
Shopify
  ✓ Store URL
  ✓ Access Token
  ✓ API Version

UyumSoft
  ✓ API Username
  ✓ API Password
  ✓ Depo ID

Kargo (Her biri için)
  ✓ API Key
  ✓ API Secret
  ✓ Username/Password
  ✓ Active checkbox
  ✓ Default checkbox

Mail (SMTP)
  ✓ Host
  ✓ Port (465/587)
  ✓ Username
  ✓ Password
  ✓ From Email
  ✓ From Name

SMS
  ✓ Provider
  ✓ API Key
  ✓ API Secret
  ✓ Header

Sync Times
  ✓ Order check: 1, 5, 10, 15 dakika
  ✓ Product sync: dakika
  ✓ Stock sync: dakika
  ✓ Cargo tracking: dakika
  ✓ Auto create shipment: checkbox
  ✓ Auto send tracking: checkbox

Templates
  ✓ Email şablonları WYSIWYG editor
  ✓ SMS şablonları (text)
  ✓ Variables: {order_id}, {customer_name}, etc.
```

---

## 🔐 GÜVENLİK KONTROL LİSTESİ

```
✅ .env'de tüm credentials
✅ SQL Injection protection (Eloquent)
✅ XSS protection (Blade escaping)
✅ CSRF protection (@csrf token)
✅ Authentication (Laravel Auth)
✅ Authorization (Roles/Permissions)
✅ API Keys encrypted
✅ HTTPS enforced (production)
✅ Rate limiting (API calls)
✅ Input validation (Form requests)
✅ Audit logs
✅ Failed job handling
```

---

## ⚡ PERFORMANS HEDEFLERI

```
✅ Page load: < 2 saniye
✅ N+1 query sorunu yok
✅ Pagination: 50 items/page
✅ Redis caching: Active
✅ Database indexes: Optimized
✅ Asset minification: Enabled
✅ Lazy loading: Images
✅ Compression: Gzip
```

---

## 📋 FASE ÖZETI

| Fase | Başlık | Saat | Durum |
|------|--------|------|-------|
| 1 | Proje Kurulumu | 2 | ⬜ TODO |
| 2 | Admin Paneli Tasarımı | 4 | ⬜ TODO |
| 3 | Database & Models | 3 | ⬜ TODO |
| 4 | Shopify Entegrasyonu | 6 | ⬜ TODO |
| 5 | UyumSoft Entegrasyonu | 6 | ⬜ TODO |
| 6 | Kargo Yönetimi | 8 | ⬜ TODO |
| 7 | Mail & SMS | 4 | ⬜ TODO |
| 8 | Ayarlar Sayfası | 4 | ⬜ TODO |
| 9 | Kullanıcı Yönetimi | 2 | ⬜ TODO |
| 10 | Raporlar | 2 | ⬜ TODO |
| 11 | Queue & Scheduler | 4 | ⬜ TODO |
| 12 | Testing | 3 | ⬜ TODO |
| 13 | Local Dev Setup | 2 | ⬜ TODO |
| 14 | Deployment Prep | 2 | ⬜ TODO |
| **TOPLAM** | | **48** | |

---

## 🛠️ SINKRONIZASYON AKIŞI

```
┌─────────────────┐
│  Shopify Orders │
└────────┬────────┘
         │
         ▼ (Her 1-5 dakika)
    ┌──────────────┐
    │ Fetch Orders │
    └────┬─────────┘
         │
         ▼
    ┌──────────────┐
    │ Save to DB   │
    └──────────────┘


┌──────────────────────┐
│ UyumSoft Products    │
└────────┬─────────────┘
         │
         ▼ (Her 30 dakika)
    ┌──────────────────┐
    │ Fetch & Map      │
    │ Variants, Images │
    └────┬─────────────┘
         │
         ▼
    ┌──────────────────┐
    │ Create/Update    │
    │ on Shopify       │
    └──────────────────┘


┌──────────────────────┐
│ Stock Updates        │
└────────┬─────────────┘
         │
         ▼ (Her 10 dakika)
    ┌──────────────────┐
    │ Get UyumSoft     │
    │ Stock            │
    └────┬─────────────┘
         │
         ▼
    ┌──────────────────┐
    │ Update Shopify   │
    │ Inventory        │
    └──────────────────┘
```

---

## 📱 RESPONSIVE DESIGN

```
xs: < 576px      (phone)
sm: ≥ 576px      (tablet)
md: ≥ 768px      (tablet)
lg: ≥ 992px      (desktop)
xl: ≥ 1200px     (desktop)
xxl: ≥ 1400px    (desktop)

Bootstrap Grid kulllan: col-12, col-md-6, col-lg-3
```

---

## 🎨 TASARIM

```
Tema: Minimal Modern (Dark/Light mode)

Renkler:
  Primary:   #2C3E50 (Lacivert)
  Secondary: #E67E22 (Turuncu)
  Success:   #27AE60 (Yeşil)
  Danger:    #E74C3C (Kırmızı)
  Warning:   #F39C12 (Sarı)
  Info:      #3498DB (Mavi)
  Light:     #ECF0F1 (Açık)
  Dark:      #34495E (Koyu)

Font: -apple-system, "Segoe UI", Roboto, sans-serif
Font Size: 14px (base), 16px (desktop)
Line Height: 1.6
Border Radius: 4px
Shadow: Minimal (0 1px 3px)

Bootstrap 5.3 + Custom CSS
Alpine.js (minimal JS)
No jQuery
```

---

## 📚 KURALLAR & STANDARTLAR

### PHP Code
```php
// PSR-12 standartları
use Illuminate\Database\Eloquent\Model;

class ShopifyOrder extends Model
{
    protected $fillable = ['shop_order_id', 'customer_name'];
    
    public function items()
    {
        return $this->hasMany(ShopifyOrderItem::class);
    }
}
```

### Blade Templates
```blade
@extends('layouts.app')

@section('title', 'Page Title')

@section('content')
    <div class="container mt-5">
        <div class="row">
            <div class="col-12 col-md-6 col-lg-3">
                <!-- Content -->
            </div>
        </div>
    </div>
@endsection
```

### Controllers
```php
class OrderController extends Controller
{
    public function index()
    {
        $orders = ShopifyOrder::paginate(50);
        return view('orders.index', compact('orders'));
    }
}
```

### Services
```php
class ShopifyService
{
    private $storeUrl;
    private $accessToken;
    
    public function getOrders($limit = 50)
    {
        return $this->makeRequest('GET', '/orders.json?limit='.$limit);
    }
    
    private function makeRequest($method, $endpoint, $data = null)
    {
        // Implementation
    }
}
```

---

## 🧪 LOCAL TEST SENARYOSU

```
1. Dashboard aç
2. Settings'e git
3. Shopify credentials gir (test credentials)
4. UyumSoft credentials gir
5. Kargo firmaları ayarla
6. Mail/SMS ayarla
7. Manual sync trigger et
8. Siparişlerin geldiğini kontrol et
9. Ürün senkronizasyonunu test et
10. Stok güncellemesini test et
11. Kargo oluştur ve label/invoice yazdır
12. Email/SMS gönder
13. Raporları kontrol et
14. Tüm sayfaları responsive test et
```

---

## ❓ SORDUKÇA SORULAN SORULAR

### Redis Windows'ta nasıl kurulur?
```
Seçenek 1: WSL2 + redis-server
Seçenek 2: Docker + Redis container
Seçenek 3: Windows Redis nativ (eski, deprecated)
→ WSL2 en kolay seçenek
```

### Shopify test credentials nereden alırım?
```
1. shopify.dev hesabı oluştur
2. Test store oluştur
3. Admin API access token oluştur
4. .env'ye kopyala
```

### Cursor nasıl kullanırım?
```
1. VS Code + Cursor extension
2. Chat aç (Ctrl + Shift + P)
3. Spesifik prompt yaz (@workspace, @file.php)
4. Cursor kodları öneriri, sen gözlemle
5. Automatic suggestions'u kabul et
6. Local'de test et
```

### Tüm projesi kaç saat sürüyor?
```
Sadece coding: 40-50 saat
Testing & bug fixes: +10 saat
Deployment: +2 saat
TOPLAM: ~60 saat (1 hafta intensive)
```

### Production server'a nasıl deploy edilir?
```
1. VPS/Dedicated server al (4GB RAM, 2 Core)
2. Ubuntu 20.04+ yükle
3. Nginx + PHP-FPM yükle
4. MySQL, Redis kur
5. SSL certificate (Let's Encrypt)
6. GitHub'dan clone
7. composer install
8. php artisan migrate
9. Cron job schedule
10. Monitor & backup setup
```

---

## 📞 HIZLI KOMUTLAR

```bash
# Development
php artisan serve              # Server başlat
php artisan queue:work         # Queue worker
npm run dev                    # CSS/JS watch
npm run build                  # Production build

# Database
php artisan migrate            # Migrate
php artisan migrate:fresh      # Reset + migrate
php artisan migrate:rollback   # Geri al
php artisan db:seed            # Seed data
php artisan tinker             # Interactive shell

# Make commands
php artisan make:model Model -m -c -r    # Model + migration + controller
php artisan make:controller ControllerName
php artisan make:migration create_table
php artisan make:job JobName
php artisan make:command CommandName

# Testing
php artisan test               # Tüm testler
php artisan test --filter=Test  # Spesifik test

# Cache & Queue
php artisan cache:clear       # Cache temizle
php artisan queue:failed      # Failed jobs
php artisan queue:retry all   # Retry failed

# Deployment
php artisan config:cache      # Config cache
php artisan route:cache       # Route cache
php artisan view:cache        # View cache
php artisan storage:link      # Storage symlink
```

---

## 📖 REFERANSLAR

```
Laravel:  https://laravel.com/docs/11.x
Shopify:  https://shopify.dev/api
Bootstrap: https://getbootstrap.com/docs/5.3
Alpine.js: https://alpinejs.dev
MySQL:    https://dev.mysql.com/doc
Redis:    https://redis.io
```

---

## ✅ BAŞLAMA KONTROL LİSTESİ

```
[ ] Gerekli yazılımlar yüklü (PHP, MySQL, Redis, Node, Composer)
[ ] Laravel projesi oluşturuldu
[ ] .env dosyası ayarlandı
[ ] APP_KEY oluşturuldu
[ ] Database oluşturuldu ve migrated
[ ] .cursorrules dosyası kopyalandı
[ ] 3 terminal açık (server, queue, redis)
[ ] Cursor IDE açık
[ ] Admin hesabı oluşturuldu
[ ] http://localhost:8000 açılıyor
[ ] Dashboard erişilebiliyor
```

**Hepsi ✅ ise FASE 2'ye başlayabilirsin!**

---

## 🎯 ÖNERİLEN İŞ AKIŞI

### Günlük (5-6 saat)

```
1. Terminal'leri başlat
2. Cursor açık tut
3. TODO.md'den günün fazesi seç
4. Cursor'de prompt yaz
5. Kodları gözlemle
6. Local'de test et
7. Hata varsa debug et
8. Bir fase tamamla
```

### Haftalık

```
1. Pazartesi: FASE 2-3 (UI & Database)
2. Salı-Çarşamba: FASE 4-5 (Shopify & UyumSoft)
3. Perşembe: FASE 6-7 (Kargo, Mail & SMS)
4. Cuma: FASE 8-11 (Ayarlar, Sync, Scheduler)
5. Cumartesi: FASE 12-13 (Testing & Local)
6. Pazar: FASE 14 (Deployment Prep)
```

---

**Hazır mısın? Başlayalım! 🚀**

Sıradaki dosya: `01-BASLANGIC_REHBERI.md`
