# 📋 EtiCart - Detaylı TODO Listesi
## Cursor AI için Otomatik Devam Dosyası

---

## 🚀 FASE 1: PROJE KURULUMU (2 saat)
**Başlangıç:** Cursor'de "@workspace" ile başla, sonra sırayla çalışmalara devam et

### ✅ 1.1 - Laravel Projesi Oluşturma
- [x] `composer create-project laravel/laravel eticart` çalıştır *(PHP 8.1 uyumu: Laravel 10)*
- [x] Node.js dependencies yükle: `npm install`
- [x] `.env.example`'dan `.env` oluştur: `cp .env.example .env`
- [x] APP_KEY oluştur: `php artisan key:generate`
- [x] Database bağlantısı .env'e gir (MySQL)
- [x] `php artisan migrate:fresh` ile test migration çalıştır

### ✅ 1.2 - Gerekli Packages Yükleme
```bash
composer require:
- guzzlehttp/guzzle (API calls için)
- redis (Cache/Queue için)
- intervention/image (Image manipulation)
- barryvdh/laravel-dompdf (PDF generation)
- maatwebsite/excel (Excel export)
- spatie/laravel-permission (Role & permission)

npm install:
- bootstrap@5.3.0
- bootstrap-icons
- alpinejs
```

### ✅ 1.3 - Authentication Kurulumu
- [x] `php artisan make:auth` (Breeze/Jetstream)
- [x] Users table migration kontrol et
- [x] Login/Register sayfalarını özelleştir (minimal tasarım)
- [ ] Password reset logic test et
- [x] Session timeout ayarla (.env'de SESSION_LIFETIME=120)

### ✅ 1.4 - Temel Klasör Yapısı Oluştur
```bash
mkdir -p: ✅ tamamlandı
- app/Services
- app/Jobs
- app/Events
- app/Traits
- resources/views/components
- resources/views/layouts
- storage/cargo_labels
- storage/invoices
- storage/imports
```

---

## 🎨 FASE 2: ADMIN PANELI TASARIMI (4 saat)

### ✅ 2.1 - Bootstrap Temasını Özelleştir
- [x] `resources/css/app.css` oluştur
- [x] Bootstrap variables override et (renkler, typography)
- [x] Dark mode CSS variables ekle
- [ ] Responsive breakpoint test et
- [x] Print CSS rules ekle (yazdırma için)

**Bootstrap Kustom Renkleri:**
```css
$primary: #2C3E50 (Lacivert)
$secondary: #E67E22 (Turuncu)
$success: #27AE60 (Yeşil)
$danger: #E74C3C (Kırmızı)
$warning: #F39C12 (Sarı)
$info: #3498DB (Mavi)
$light: #ECF0F1 (Açık Gri)
$dark: #34495E (Koyu Gri)
```

### ✅ 2.2 - Layout Components
- [x] `resources/views/layouts/app.blade.php` (Ana layout)
- [x] Navbar component (`resources/views/components/navbar.blade.php`)
- [x] Sidebar component (`resources/views/components/sidebar.blade.php`)
- [x] Footer component (opsiyonel)
- [x] Breadcrumb component
- [x] Alert/Toast component

**Navbar İçeriği:**
```
- Logo & Proje Adı (Sol)
- Quick search (Orta)
- User menu & Logout (Sağ)
- Notification bell (sağ)
```

**Sidebar Menüsü:**
```
- Dashboard
- Orders (Siparişler)
- Products (Ürünler)
- Shipments (Kargo)
- Settings (Ayarlar)
- Users (Kullanıcılar)
- Reports (Raporlar)
```

### ✅ 2.3 - Dashboard Sayfası
- [x] `resources/views/dashboard.blade.php` oluştur
- [x] Widget components oluştur:
  - [x] Last Orders (Son 5 sipariş)
  - [x] Sync Status (Senkronizasyon durumu)
  - [x] Quick Stats (4x card: Orders, Products, Revenue, Shipments)
  - [x] System Health (Sistem sağlığı)
- [x] Chart kütüphanesi ekle (Chart.js)
- [ ] Real-time update (JavaScript/AJAX)

### ✅ 2.4 - Common Components
- [x] `resources/views/components/table.blade.php` (Tablo template)
- [x] `resources/views/components/form.blade.php` (Form template)
- [x] `resources/views/components/modal.blade.php` (Modal template)
- [x] `resources/views/components/badge.blade.php` (Badge component)
- [x] `resources/views/components/loading.blade.php` (Loading spinner)
- [x] `resources/views/components/empty-state.blade.php` (Boş state)

### ✅ 2.5 - JavaScript Helpers
- [x] `resources/js/app.js` oluştur
- [x] Toast notifications helper
- [x] Confirm dialog helper
- [x] AJAX helper functions
- [x] Form validation helper

---

## 🗄️ FASE 3: DATABASE & MODELS (3 saat)

### ✅ 3.1 - Tüm Migrations Oluştur
```bash
✅ create_settings_table
✅ create_uyumsoft_products_table
✅ create_shopify_products_table
✅ create_shopify_orders_table
✅ create_shopify_order_items_table
✅ create_cargo_companies_table
✅ create_shipments_table
✅ create_sync_jobs_table
✅ create_sync_job_logs_table
✅ create_mail_templates_table
✅ create_sms_templates_table
✅ create_notifications_table (message_notifications)
```

### ✅ 3.2 - Models Oluştur
```bash
✅ Setting, UyumSoftProduct, ShopifyProduct, ShopifyOrder,
✅ ShopifyOrderItem, Shipment, CargoCompany, SyncJob,
✅ SyncJobLog, MailTemplate, SmsTemplate, Notification
```

### ✅ 3.3 - Model Relationships
- [x] User > ShopifyOrder (one-to-many)
- [x] ShopifyOrder > ShopifyOrderItem (one-to-many)
- [x] ShopifyOrder > Shipment (one-to-many)
- [x] UyumSoftProduct > ShopifyProduct (one-to-one)
- [x] Shipment > CargoCompany (belongs-to)
- [x] SyncJob > SyncJobLog (one-to-many)

### ✅ 3.4 - Database Seeders
- [x] `DatabaseSeeder.php` - Varsayılan veri
- [x] `SettingsSeeder.php` - Ayarları initialize et
- [x] `UserSeeder.php` - Test kullanıcıları
- [x] `CargoCompanySeeder.php` - Kargo firmaları
- [x] `TemplateSeeder.php` - Mail/SMS şablonları + sync jobs

**Seed Data:**
```php
// Settings
Settings: shopify_store_url, shopify_access_token, uyumsoft_api_user, etc.

// Cargo Companies
Aras Kargo, MNG Kargo, Yurtiçi Kargo, PTT

// Mail Templates
Order Confirmation, Shipment Notification, etc.

// SMS Templates
Order Confirmation SMS, Shipment SMS, etc.
```

### ✅ 3.5 - Database Tests
- [x] Local MySQL test database oluştur (`eticart` / `eticart_test`)
- [x] `php artisan migrate` çalıştır
- [x] `php artisan db:seed` çalıştır
- [x] Tüm tables'ı kontrol et
- [x] Relationships test et

---

## 🔌 FASE 4: SHOPIFY ENTEGRASYONU (6 saat)

### ✅ 4.1 - Shopify Service Oluştur
**Dosya:** `app/Services/ShopifyService.php` ✅

### ✅ 4.2 - Shopify Configuration
- [x] `config/services.php` güncelle
- [x] Shopify store URL ve token .env'ye ekle
- [x] API version tanımla (2024-01)
- [x] Base URL constant'ını tanımla

### ✅ 4.3 - Order Sync Controller
**Dosya:** `app/Http/Controllers/OrderController.php` ✅
- index, show, sync, updateStatus, assignCargo

### ✅ 4.4 - Order Sync Job
**Dosya:** `app/Jobs/SyncShopifyOrders.php` ✅
**Dosya:** `app/Services/OrderSyncService.php` ✅

### ✅ 4.5 - Order Views
- [x] `resources/views/orders/index.blade.php` (Tüm siparişler)
- [x] `resources/views/orders/show.blade.php` (Sipariş detayı)

### ✅ 4.6 - Testing
- [ ] Shopify API bağlantısı test et *(credential girilince)*
- [x] Order fetch/save kodu hazır
- [x] Manual sync trigger hazır
- [x] Order detail view hazır

---

## 🏷️ FASE 5: UYUMSOFT ENTEGRASYONU (6 saat)

### ✅ 5.1 - UyumSoft Service
**Dosya:** `app/Services/UyumSoftService.php` ✅

### ✅ 5.2 - UyumSoft Configuration
- [x] `config/services.php` güncelle
- [x] Kullanıcı adı, şifre, depo ID .env'ye ekle
- [x] API base URL tanımla

### ✅ 5.3 - Product Sync Controller
**Dosya:** `app/Http/Controllers/ProductController.php` ✅

### ✅ 5.4 - Product Sync Job
**Dosya:** `app/Jobs/SyncUyumSoftProducts.php` ✅
**Dosya:** `app/Services/ProductSyncService.php` ✅

### ✅ 5.5 - Product Views
- [x] `resources/views/products/index.blade.php`
- [x] `resources/views/products/sync.blade.php`
- [x] `resources/views/products/edit.blade.php`
- [x] `resources/views/products/show.blade.php`

### ✅ 5.6 - Stock Sync Job
**Dosya:** `app/Jobs/SyncStock.php` ✅

### ✅ 5.7 - Testing
- [ ] UyumSoft API bağlantısı test et *(credential girilince)*
- [x] Product fetch/save kodu hazır
- [x] Variant mapping / Shopify push kodu hazır
- [x] Stock sync kodu hazır

---

## 🚚 FASE 6: KARGO ENTEGRASYONU (8 saat)

### ✅ 6.1 - Kargo Service Abstract
**Dosya:** `app/Services/Cargo/CargoServiceInterface.php` ✅

### ✅ 6.2 - Kargo Provider Implementations
- [x] `app/Services/Cargo/ArasCargoService.php`
- [x] `app/Services/Cargo/MngCargoService.php`
- [x] `app/Services/Cargo/YurticiCargoService.php` *(TODO adı YurticiBirligiService idi)*
- [x] `app/Services/Cargo/PttCargoService.php`
- [x] Local mode: credential yoksa takip no üretir

### ✅ 6.3 - Cargo Manager Service
**Dosya:** `app/Services/CargoService.php` ✅

### ✅ 6.4 - Shipment Model & Migration
- [x] Model relationships
- [x] Status enum (pending, shipped, delivered, returned, cancelled)
- [x] Database fields

### ✅ 6.5 - Shipment Controller
**Dosya:** `app/Http/Controllers/ShipmentController.php` ✅

### ✅ 6.6 - Shipment Views
- [x] `resources/views/shipments/index.blade.php`
- [x] `resources/views/shipments/create.blade.php`
- [x] `resources/views/shipments/show.blade.php`
- [x] `resources/views/shipments/print-label.blade.php`
- [x] `resources/views/shipments/print-invoice.blade.php`

### ✅ 6.7 - Cargo Jobs
- [x] `app/Jobs/CreateShipment.php`
- [x] `app/Jobs/UpdateCargoTracking.php`
- [x] `app/Jobs/GenerateCargoLabel.php`
- [x] `app/Jobs/SendTrackingInfo.php`

### ✅ 6.8 - Testing
- [x] Local mode kargo oluşturma hazır
- [ ] Gerçek kargo API credential testleri *(canlı credential sonrası)*

---

## 📧 FASE 7: MAIL & SMS ENTEGRASYONU (4 saat)

### ✅ 7.1 - Mail Configuration
- [x] `config/mail.php` / `.env` (local: `MAIL_MAILER=log`)
- [x] SMTP credentials .env'ye ekle
- [x] From email & name tanımla
- [x] Queue uyumlu mailable yapı

### ✅ 7.2 - Mail Service
**Dosya:** `app/Services/MailService.php` ✅

### ✅ 7.3 - Mail Templates
- [x] `resources/views/email/order-confirmation.blade.php`
- [x] `resources/views/email/shipment-notification.blade.php`
- [x] `resources/views/email/order-status-update.blade.php`
- [x] Template model & database (mevcut + editor)

### ✅ 7.4 - SMS Service
**Dosya:** `app/Services/SmsService.php` ✅ (log/local + Netgsm)

### ✅ 7.5 - SMS Templates
- [x] Database structure
- [x] Template variables
- [x] Admin panel editor (`notifications/templates`)

### ✅ 7.6 - Notification Views
- [x] `resources/views/notifications/index.blade.php`
- [x] Filter + Resend + Test gönderimi

### ✅ 7.7 - Testing
- [x] Mail sending test (local: log driver)
- [x] SMS local/log test
- [x] Template variable replacement hazır

---

## ⚙️ FASE 8: AYARLAR SAYFASI (4 saat)

### ✅ 8.1 - Settings Model & Migration
- [x] `Setting` model
- [x] key-value store
- [x] cache invalidation

### ✅ 8.2 - Settings Controller
**Dosya:** `app/Http/Controllers/SettingsController.php` ✅

### ✅ 8.3 - Settings Views
- [x] `resources/views/settings/shopify.blade.php`
- [x] `resources/views/settings/uyumsoft.blade.php`
- [x] `resources/views/settings/cargo.blade.php`
- [x] `resources/views/settings/mail.blade.php`
- [x] `resources/views/settings/sms.blade.php`
- [x] `resources/views/settings/sync.blade.php`
- [x] `resources/views/settings/templates/mail.blade.php`
- [x] `resources/views/settings/templates/sms.blade.php`

### ✅ 8.4 - Settings Index/Menu
- [x] `resources/views/settings/index.blade.php`

### ✅ 8.5 - Settings Validation
- [x] Controller validation
- [x] API connectivity test endpoints (Shopify/UyumSoft/Mail/SMS)

---

## 👥 FASE 9: KULLANICI YÖNETİMİ (2 saat)

### ✅ 9.1 - User Model & Roles
- [x] Add role column (admin, manager, viewer) — Spatie roles
- [x] Add is_active column
- [x] Permissions setup (Laravel Permission package)

### ✅ 9.2 - User Controller
**Dosya:** `app/Http/Controllers/UserController.php`

```php
class UserController extends Controller {
    public function index() // Kullanıcı listesi
    public function create() // Yeni kullanıcı form
    public function store($data) // Kullanıcı oluştur
    public function edit($id) // Düzenle form
    public function update($id, $data) // Kaydet
    public function deactivate($id) // Devre dışı bırak
    public function resetPassword($id) // Şifre reset
}
```
- [x] UserController tamamlandı (activate dahil)

### ✅ 9.3 - User Views
- [x] `resources/views/users/index.blade.php`
  - Tablo: Name, Email, Role, Status, Created
  - Filter: Role, Status
  - Actions: Edit, Deactivate, Reset Password

- [x] `resources/views/users/form.blade.php`
  - Name, Email, Password
  - Role select (Admin, Manager, Viewer)
  - Permissions checkboxes
  - Active status checkbox

### ✅ 9.4 - Middleware
- [x] Check admin role
- [x] Check manager role
- [x] Check active user

---

## 📊 FASE 10: RAPORLAR (2 saat)

### ✅ 10.1 - Report Service
**Dosya:** `app/Services/ReportService.php`

```php
class ReportService {
    public function getSalesReport($dateFrom, $dateTo)
    public function getProductReport($dateFrom, $dateTo)
    public function getSyncReport($dateFrom, $dateTo)
    public function getShipmentReport($dateFrom, $dateTo)
}
```
- [x] ReportService tamamlandı (+ sistem logları)

### ✅ 10.2 - Report Controller
- [x] Get reports
- [x] Filter by date
- [x] Export to CSV/PDF

### ✅ 10.3 - Report Views
- [x] `resources/views/reports/sales.blade.php`
  - Chart: Revenue over time
  - Table: Sales by day
  - Filters: Date range

- [x] `resources/views/reports/sync-logs.blade.php`
  - Table: Sync history
  - Status: Success/Failed
  - Details: Synced count, errors
  - Filter: Type, Date

- [x] `resources/views/reports/system-logs.blade.php`
  - Recent errors
  - API failures
  - Performance issues

---

## 🔄 FASE 11: QUEUE & SCHEDULING (4 saat)

### ✅ 11.1 - Queue Configuration
- [x] Redis queue driver ayarla — localhost: `database` (Redis yok)
- [x] Queue timeout ayarla (600 sn) + `QUEUE_RETRY_AFTER=650`
- [x] Retry logic (`$tries` on jobs)
- [x] Failed jobs handling (monitor + retry/flush)

### ✅ 11.2 - Scheduler (Kernel.php)
```php
// Her 1 dakikada
$schedule->job(new SyncShopifyOrders)
    ->everyMinute()
    ->withoutOverlapping();

// Her 10 dakikada
$schedule->job(new SyncStock)
    ->everyTenMinutes()
    ->withoutOverlapping();

// Her 30 dakikada
$schedule->job(new SyncUyumSoftProducts)
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// Her saat
$schedule->job(new UpdateCargoTracking)
    ->hourly()
    ->withoutOverlapping();

// Günlük (saat 2'de)
$schedule->job(new GenerateDailyReport)
    ->dailyAt('02:00')
    ->withoutOverlapping();
```
- [x] Scheduler tanımlandı (`is_active` kontrolü ile)

### ✅ 11.3 - Queue Monitor
- [x] `resources/views/admin/queue-status.blade.php`
  - Pending jobs count
  - Failed jobs
  - Recent jobs
  - Retry failed button

### ✅ 11.4 - Testing
- [x] Queue locally test (database driver + monitor test dispatch)
- [x] Job execution test
- [x] Failed job handling test

---

## 🧪 FASE 12: TESTING (3 saat)

### ✅ 12.1 - Unit Tests
```
tests/Unit/Services/
- ShopifyServiceTest.php
- UyumSoftServiceTest.php
- CargoServiceTest.php
- MailServiceTest.php
```
- [x] Unit testler yazıldı

### ✅ 12.2 - Feature Tests
```
tests/Feature/
- AuthTest.php
- OrderControllerTest.php
- ProductControllerTest.php
- ShipmentControllerTest.php
- QueueJobTest.php
```
- [x] Feature testler yazıldı

### ✅ 12.3 - Test Database
- [x] SQLite in-memory database (`phpunit.xml`)
- [x] Migrations run (RefreshDatabase)
- [x] Factories & seeders (UserFactory `is_active` + plain password)

### ✅ 12.4 - Run Tests
```bash
php artisan test
php artisan test --filter=ShopifyService
php artisan test --coverage
```
- [x] `php artisan test` — 48 passed

---

## 🚀 FASE 13: LOCAL DEVELOPMENT SETUP (2 saat)

### ✅ 13.1 - Development Environment
```bash
# Database
mysql -u root -p
CREATE DATABASE eticart;

# Redis
# Localhost: Redis yok → CACHE_DRIVER=file, QUEUE_CONNECTION=database

# Application
php artisan serve
php artisan queue:work (separate terminal)
php artisan schedule:work (separate terminal)
```
- [x] MySQL `eticart` + Laravel 10 (PHP 8.1)
- [x] File cache / database queue / mail log
- [x] Seed: `admin@eticart.com` / `password123`

### ✅ 13.2 - Testing Flow
- [x] Register admin user (seeder)
- [x] Configure all settings (UI `/settings`)
- [ ] Test Shopify sync (manual — API anahtarı gerekir)
- [ ] Test UyumSoft sync (manual — API anahtarı gerekir)
- [x] Test order/cargo/mail/SMS views (local log mode)
- [x] Test all main views (dashboard, orders, products, shipments, notifications, settings, users, reports, queue)
- [x] Responsive Bootstrap layout

### ✅ 13.3 - Performance Testing
- [x] Pagination on lists
- [x] Eager loading where used (orders/users)
- [x] Indexes on migrations
- [ ] Large dataset stress (production öncesi)

---

## 📦 FASE 14: DEPLOYMENT PREPARATION (2 saat)

### ✅ 14.1 - Production Configuration
- [ ] Generate unique APP_KEY
- [ ] Setup production database
- [ ] Configure Redis
- [ ] Setup SSL certificate (Let's Encrypt)
- [ ] Configure Nginx
- [ ] Setup PHP-FPM

### ✅ 14.2 - Environment Files
- [ ] `.env.example` update
- [ ] `.env.production` create
- [ ] `config/app.php` review
- [ ] `config/database.php` review
- [ ] `config/cache.php` review

### ✅ 14.3 - Security Hardening
- [ ] HTTPS only
- [ ] CORS configuration
- [ ] Rate limiting
- [ ] SQL injection tests
- [ ] XSS tests
- [ ] CSRF tests

### ✅ 14.4 - Backup & Monitoring
- [ ] Database backup script
- [ ] File backup script
- [ ] Monitoring setup
- [ ] Error logging
- [ ] Uptime monitoring

---

## 📝 GENEL NOTLAR

### Her Aşama Tamamlandığında:
- [ ] Code review (self-review)
- [ ] Security check
- [ ] Performance check
- [ ] Tests run
- [ ] Documentation updated
- [ ] Commit to git

### Cursor Workflow:
1. Her task başında `@workspace` kontrol et
2. Mevcut yapıyı anla
3. Gerekli migrations/models oluştur
4. Service layer ekle
5. Controllers yazı
6. Views oluştur
7. Tests yaz
8. Local test et

### Hata Oluştuğunda:
1. Error message oku
2. Stack trace kontrol et
3. Logs kontrol et (`storage/logs/`)
4. `.cursorrules` kontrol et
5. Sorun giderme talimatlarına bak

### Toplam Zaman Tahmini:
- **Total:** 40-50 saat
- **Local Development:** 2-3 saat
- **Deployment:** 1-2 saat

---

**Başlangıç:** Laravel kurulumundan başla
**Son Kontrol:** Tüm testler pass + Local'de stabil çalışma
**Next Step:** Production server'a deploy
