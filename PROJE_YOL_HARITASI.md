# EticcCart - Shopify & UyumSoft Entegrasyon Sistemi
## Proje Yol Haritası

---

## 📋 PROJE ÖZETI

**Amaç:** UyumSoft, Shopify, Kargo ve Email/SMS sistemlerini birleştiren minimal, modern, hızlı e-ticaret yönetim platformu

**Tech Stack:**
- Backend: Laravel 11 + PHP 8.2+
- Frontend: Blade + Bootstrap 5.3 (Minimal Modern)
- Database: MySQL 8.0+
- Queue: Redis (Arka planda senkronizasyon işlemleri)
- Web Server: Nginx + PHP-FPM (Production)
- Development: Apache/PHP built-in server (Local)

**API İntegrasyonları:**
- Shopify REST & GraphQL API
- UyumSoft API
- Yurtiçi Kargo (Aras Kargo, MNG Kargo, Yurtiçi Kargo, PTT)
- SMTP Mail
- SMS (Netgsm, Iletisim.net vb.)

---

## 🗂️ VERITABANI ŞEMASI

```
Users (Yöneticiler)
├── id, name, email, password, role, is_active
├── created_at, updated_at

Settings (Sistem Ayarları)
├── id, key, value, category (shopify, uyumsoft, kargo, mail, sms, sync)
├── created_at, updated_at

UyumSoft Integration
├── uyumsoft_products (ürün senkronizasyonu)
│   ├── id, uyumsoft_id, title, variant_info, original_price
│   ├── stock, synced_to_shopify, shopify_id, last_sync
│   └── created_at, updated_at
│
└── uyumsoft_sync_logs
    ├── id, sync_type, status, message, synced_count, error_count
    └── created_at

Shopify Integration
├── shopify_products (Shopify ürünleri)
│   ├── id, shopify_product_id, shopify_variant_id, title, price, stock
│   ├── uyumsoft_product_id (ilişki), last_sync
│   └── created_at, updated_at
│
├── shopify_orders (Sipariş senkronizasyonu)
│   ├── id, shopify_order_id, order_number, customer_name, customer_email
│   ├── total_price, payment_status, fulfillment_status
│   ├── order_items (JSON), notes, synced_at
│   └── created_at, updated_at
│
├── shopify_order_items
│   ├── id, shopify_order_id, product_title, variant_title, quantity, price
│   └── created_at
│
└── shopify_sync_logs
    ├── id, sync_type, status, message, synced_count, error_count
    └── created_at

Kargo Yönetimi
├── shipments (Kargo Gönderileri)
│   ├── id, shopify_order_id, order_number, kargo_firması
│   ├── tracking_number, tracking_url, status
│   ├── receiver_name, receiver_phone, receiver_address, receiver_city
│   ├── weight, cargo_cost, insurance, amount
│   ├── label_path, invoice_path, shipped_at
│   └── created_at, updated_at
│
└── cargo_companies (Kargo Firmaları Ayarları)
    ├── id, name, api_key, api_secret, username, password
    ├── is_active, provider_type
    └── created_at, updated_at

Mail & SMS
├── mail_templates (Email Şablonları)
│   ├── id, name, slug, subject, body, is_active
│   └── created_at, updated_at
│
├── sms_templates (SMS Şablonları)
│   ├── id, name, slug, body, is_active
│   └── created_at, updated_at
│
└── notifications (Gönderilen Mesajlar)
    ├── id, type (mail/sms), recipient, subject, body, status, sent_at
    └── created_at

Sync Jobs (Arka Plan İşleri)
├── sync_jobs
│   ├── id, job_type (order_sync, product_sync, stock_sync), status
│   ├── last_run, next_run, interval_minutes, is_active
│   ├── last_error
│   └── created_at, updated_at
│
└── sync_job_logs
    ├── id, sync_job_id, status, message, duration, error
    └── created_at
```

---

## 📁 KLASÖR YAPISI

```
eticart/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── SyncShopifyOrdersCommand.php
│   │       ├── SyncUyumSoftProductsCommand.php
│   │       ├── SyncStockCommand.php
│   │       └── ProcessQueuedJobsCommand.php
│   │
│   ├── Http/Controllers/
│   │   ├── DashboardController.php (Ana sayfa)
│   │   ├── OrderController.php (Siparişler)
│   │   ├── ProductController.php (Ürünler)
│   │   ├── ShipmentController.php (Kargo)
│   │   ├── SettingsController.php (Ayarlar)
│   │   ├── UserController.php (Kullanıcılar)
│   │   ├── ReportController.php (Raporlar)
│   │   └── Api/
│   │       ├── ShopifyController.php
│   │       ├── UyumSoftController.php
│   │       └── CargoController.php
│   │
│   ├── Models/
│   │   ├── User.php
│   │   ├── Setting.php
│   │   ├── UyumSoftProduct.php
│   │   ├── ShopifyProduct.php
│   │   ├── ShopifyOrder.php
│   │   ├── ShopifyOrderItem.php
│   │   ├── Shipment.php
│   │   ├── CargoCompany.php
│   │   ├── SyncJob.php
│   │   ├── SyncJobLog.php
│   │   ├── MailTemplate.php
│   │   └── SmsTemplate.php
│   │
│   ├── Services/
│   │   ├── ShopifyService.php (Shopify API işlemleri)
│   │   ├── UyumSoftService.php (UyumSoft API işlemleri)
│   │   ├── CargoService.php (Kargo API işlemleri)
│   │   ├── SyncService.php (Senkronizasyon logic)
│   │   ├── MailService.php (Email gönderimi)
│   │   ├── SmsService.php (SMS gönderimi)
│   │   └── ReportService.php (Rapor oluşturma)
│   │
│   ├── Jobs/
│   │   ├── SyncShopifyOrders.php
│   │   ├── SyncUyumSoftProducts.php
│   │   ├── SyncStock.php
│   │   ├── GenerateCargoLabel.php
│   │   └── SendNotification.php
│   │
│   └── Events/
│       ├── OrderSynced.php
│       ├── ProductSynced.php
│       └── ShipmentCreated.php
│
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   │   └── app.blade.php (Ana layout - Bootstrap minimal)
│   │   ├── dashboard.blade.php (Ana sayfa / Dashboard)
│   │   ├── orders/
│   │   │   ├── index.blade.php (Siparişler listesi)
│   │   │   └── show.blade.php (Sipariş detayı)
│   │   ├── products/
│   │   │   ├── index.blade.php (Ürünler listesi)
│   │   │   ├── sync.blade.php (UyumSoft → Shopify)
│   │   │   └── bulk-edit.blade.php (Toplu düzenleme)
│   │   ├── shipments/
│   │   │   ├── index.blade.php (Kargo listesi)
│   │   │   ├── create.blade.php (Yeni kargo oluştur)
│   │   │   └── show.blade.php (Kargo detayı & yazdırma)
│   │   ├── settings/
│   │   │   ├── shopify.blade.php (Shopify ayarları)
│   │   │   ├── uyumsoft.blade.php (UyumSoft ayarları)
│   │   │   ├── cargo.blade.php (Kargo ayarları)
│   │   │   ├── mail.blade.php (Mail ayarları)
│   │   │   ├── sms.blade.php (SMS ayarları)
│   │   │   ├── sync.blade.php (Sinkronizasyon ayarları)
│   │   │   └── index.blade.php (Ayarlar ana sayfa)
│   │   ├── users/
│   │   │   ├── index.blade.php (Kullanıcılar listesi)
│   │   │   └── form.blade.php (Kullanıcı düzenle)
│   │   ├── reports/
│   │   │   ├── sales.blade.php (Satış raporları)
│   │   │   └── sync-logs.blade.php (Sinkronizasyon logları)
│   │   ├── components/
│   │   │   ├── navbar.blade.php
│   │   │   ├── sidebar.blade.php
│   │   │   ├── alert.blade.php
│   │   │   └── modal.blade.php
│   │   └── email/
│   │       ├── order-shipped.blade.php
│   │       └── order-status.blade.php
│   │
│   ├── css/
│   │   └── app.css (Bootstrap kustomize, minimal modern)
│   │
│   └── js/
│       └── app.js (Alpine.js minimal interaktivite)
│
├── routes/
│   ├── web.php (Web rotaları)
│   └── api.php (API rotaları)
│
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000000_create_users_table.php
│   │   ├── 2024_01_01_000001_create_settings_table.php
│   │   ├── 2024_01_01_000002_create_uyumsoft_products_table.php
│   │   ├── 2024_01_01_000003_create_shopify_products_table.php
│   │   ├── 2024_01_01_000004_create_shopify_orders_table.php
│   │   ├── 2024_01_01_000005_create_shipments_table.php
│   │   ├── 2024_01_01_000006_create_sync_jobs_table.php
│   │   └── ... (diğer migration dosyaları)
│   │
│   ├── seeders/
│   │   ├── DatabaseSeeder.php
│   │   └── SettingsSeeder.php
│   │
│   └── factories/
│       └── (gerekli factory'ler)
│
├── storage/
│   └── cargo_labels/ (Kargo etiketleri)
│   └── invoices/ (Faturalar)
│   └── logs/ (Sistem logları)
│
├── .cursorrules (Cursor AI kuralları)
├── .env.example
├── .env (local development)
├── composer.json
└── README.md
```

---

## 🎯 MENÜ YAPISI (Sidebar)

```
📊 Dashboard
├── Son Siparişler
├── Senkronizasyon Durumu
├── Hızlı İstatistikler

📦 Siparişler
├── Tüm Siparişler
├── Beklemede
├── Kargoda
├── Tamamlanmış
├── Sorunlu Siparişler

🏷️ Ürünler
├── UyumSoft Ürünleri
├── Shopify Ürünleri
├── Senkronizasyon Yönetimi
├── Stok Güncellemeleri
├── Fiyat Güncellemeleri

🚚 Kargo Yönetimi
├── Kargo Gönderileri
├── Yeni Kargo Oluştur
├── Etiket Yazdır
├── Fatura Yazdır
├── Kargo Firmaları

⚙️ Ayarlar
├── Shopify Bağlantısı
├── UyumSoft Bağlantısı
├── Kargo Ayarları
├── Mail Ayarları
├── SMS Ayarları
├── Sinkronizasyon Zaman Ayarları
├── Email/SMS Şablonları

👥 Kullanıcılar
├── Yöneticileri Listele
├── Yeni Yönetici Ekle
├── Kullanıcı İzinleri

📊 Raporlar
├── Satış Raporları
├── Sinkronizasyon Logları
├── Sistem Logları

🔐 Güvenlik
├── Şifre Değiştir
├── Oturum Yönetimi
```

---

## 🎨 TASARIM PRENSIPLEERI

**Tema:** Minimal Modern Dark/Light
- Bootstrap 5.3 (custom CSS)
- Renkler: Lacivert (#2C3E50), Turuncu (#E67E22), Gri (#ECF0F1)
- Font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto
- Sayfalar: Hızlı açılıyor (Server-side render, minimal JS)
- Responsive: Mobil uyumlu (Tablet, Desktop)
- Erişilebilirlik: WCAG 2.1 AA standartları

**İkon Seti:** Bootstrap Icons
**Tablo Gösterim:** DataTables (minimal JS, pagination)
**Form Validation:** Laravel + client-side HTML5
**Notification:** Toast messages (top-right)

---

## 🔄 ÖZELLİKLER DETAYLI

### 1️⃣ DASHBOARD
- Real-time senkronizasyon durumu
- Son 10 sipariş
- Bekleyen işler
- Sistem sağlık kontrol
- Kargo entegrasyon durumları
- Hızlı eylem butonları

### 2️⃣ SİPARİŞ YÖNETİMİ
- Shopify siparişlerini liste halinde göster
- Filtreleme: Durum, Tarih, Müşteri
- Sipariş detayı: Ürünler, Müşteri bilgisi, Ödeme durumu
- Kargo atama
- Durum güncellemesi
- Müşteriye mesaj gönderme (Email/SMS)
- Sipariş notları ekleme

### 3️⃣ ÜRÜN & STOK YÖNETİMİ
- UyumSoft'tan ürün listesi
- Varyantlı ürün desteği
- Toplu seçim ve işlem
- Shopify'a aktarma (tek/toplu)
- Fiyat senkronizasyonu
- Stok senkronizasyonu
- Ürün düzenleme (başlık, açıklama, görsel)

### 4️⃣ KARGO YÖNETİMİ
- Siparişe kargo atama
- Kargo firması seçimi (Aras, MNG, Yurtiçi Kargo, PTT)
- Takip numarası otomatik alınması
- Kargo etiketi yazdırma (A4/thermal)
- Kargo faturası yazdırma
- Takip linki gönderme (SMS/Email)
- Kargo durumu takibi

### 5️⃣ AYARLAR SAYFASI
**Shopify:**
- Store URL
- Access Token
- API ayarları

**UyumSoft:**
- API Kullanıcı adı
- API Şifresi
- Depo ID

**Kargo Firmaları (Her biri için):**
- API Key
- API Secret
- Kullanıcı adı / Şifre
- Etkinleştir/Devre dışı bırak

**Mail:**
- SMTP Host
- SMTP Port
- SMTP Kullanıcı adı
- SMTP Şifresi
- From Email
- From Name

**SMS:**
- SMS Sağlayıcı seçimi
- API Key
- Başlık

**Sinkronizasyon Zaman Ayarları:**
- Sipariş kontrol aralığı (dakika)
- Ürün senkronizasyonu (dakika)
- Stok güncellemesi (dakika)
- Kargo durumu kontrol (dakika)
- Otomatik kargo oluştur (Evet/Hayır)

**Şablonlar:**
- Email şablonları editörü
- SMS şablonları editörü
- Değişken seçimi ({order_id}, {customer_name}, vb.)

### 6️⃣ KULLANICILAR / YÖNETİCİLER
- Yönetici listesi
- Yeni yönetici ekleme
- Rol yönetimi (Admin, Manager, Viewer)
- Kullanıcı devre dışı bırakma

### 7️⃣ RAPORLAR
- Satış raporları (Tarih aralığı, grafik)
- Sinkronizasyon logları
- Sistem hatası logları
- CSV/PDF export

---

## 🔌 API ENTEGRASYONLARı

### Shopify API
```
GET /admin/api/2024-01/orders.json (Siparişler)
GET /admin/api/2024-01/products.json (Ürünler)
POST /admin/api/2024-01/products.json (Yeni ürün)
PUT /admin/api/2024-01/products/{id}.json (Ürün düzenleme)
POST /admin/api/2024-01/fulfillments.json (Kargo işaretle)
```

### UyumSoft API
```
GET /api/products (Ürün listesi)
GET /api/product/{id} (Ürün detayı)
GET /api/stocks (Stok bilgisi)
POST /api/stocks (Stok güncelle)
GET /api/invoices (Faturalar)
```

### Kargo API'leri
- **Aras Kargo**: Barcode API, Tracking API
- **MNG Kargo**: Web Service, Tracking API
- **Yurtiçi Kargo**: API Integration
- **PTT**: REST API

### Email: SMTP (Laravel Mail)
### SMS: Netgsm / İletişim.net

---

## ⚡ PERFORMANS OPTİMİZASYONLARı

1. **Database Indexing**: Sık sorgulanan alanlara index
2. **Query Caching**: Redis ile sorgu cache
3. **Lazy Loading**: Ürün görselleri lazy load
4. **Queue Jobs**: Arka planda ağır işlemler
5. **Rate Limiting**: API çağrılarına limit
6. **Pagination**: Listeler sayfa bazlı
7. **Asset Minification**: CSS/JS sıkıştırma
8. **Database Connection Pooling**: Bağlantı havuzu

---

## 🔐 GÜVENLİK

1. **Authentication**: Laravel Auth (Session-based)
2. **Authorization**: Laravel Gates & Policies
3. **CSRF Protection**: CSRF token
4. **SQL Injection Protection**: Eloquent ORM
5. **XSS Protection**: Blade escaping
6. **Rate Limiting**: IP-based
7. **API Keys**: Şifreli saklama
8. **HTTPS Only**: Production ortamında
9. **Audit Logs**: Kullanıcı işlemleri kaydedilir

---

## 📱 RESPONSIVE DESIGN

- **Desktop**: 1920px+
- **Tablet**: 768px - 1024px
- **Mobile**: 320px - 767px
- **Bootstrap Grid**: Responsive breakpoints

---

## 🚀 DEPLOYMENT

**Local Development:**
```
OS: Windows/Mac/Linux
PHP: 8.2+
MySQL: 8.0+
Redis: 7.0+
Composer: Latest
Node.js: 18+ (Asset compilation)
```

**Production Server:**
```
OS: Linux (Ubuntu 20.04+)
PHP: 8.2+ (PHP-FPM)
MySQL: 8.0+ (Managed Database)
Redis: 7.0+ (Cache & Queue)
Nginx: Latest
SSL: Let's Encrypt
Storage: NVMe SSD
RAM: 4GB+ (başlangıç)
CPU: 2 Core+ (başlangıç)
```

---

## 📊 SINKRONIZASYON AKIŞI

```
┌─────────────────────────────────────────────┐
│  Shopify Orders                             │
└────────────┬────────────────────────────────┘
             │
             ▼
    ┌─────────────────┐
    │  Check Orders   │ (Her 1-5 dakika)
    │  Service Layer  │
    └────────┬────────┘
             │
             ▼
    ┌─────────────────────────────┐
    │  Save to Database           │
    │  Create Shipment (Optional) │
    └─────────────────────────────┘

┌─────────────────────────────────────────────┐
│  UyumSoft Products                          │
└────────────┬────────────────────────────────┘
             │
             ▼
    ┌─────────────────┐
    │ Fetch Products  │ (Her 30 dakika)
    │ & Variants      │
    └────────┬────────┘
             │
             ▼
    ┌──────────────────────────────────┐
    │ Map to Shopify Format            │
    │ - Title, Description, Price      │
    │ - Variants, Images               │
    └────────┬─────────────────────────┘
             │
             ▼
    ┌──────────────────────────────────┐
    │ Create/Update on Shopify         │
    │ Via GraphQL/REST API             │
    └──────────────────────────────────┘

┌─────────────────────────────────────────────┐
│  Stock Updates                              │
└────────────┬────────────────────────────────┘
             │
             ▼
    ┌─────────────────────┐
    │ Get UyumSoft Stock  │ (Her 10 dakika)
    └────────┬────────────┘
             │
             ▼
    ┌──────────────────────────┐
    │ Update Shopify Inventory │
    └──────────────────────────┘
```

---

## 📝 İŞ YÜKÜ VE TÜM AŞAMALAR

### Fase 1: Proje Kurulumu (2 saat)
- Laravel yükleme
- Database kurulumu
- Authentication sistemi
- Temel layout

### Fase 2: Admin Paneli Tasarımı (4 saat)
- Dashboard
- Sidebar & Navigation
- Bootstrap teması
- CSS customization

### Fase 3: Shopify Entegrasyonu (6 saat)
- API bağlantısı
- Order sync
- Product management
- Stock sync

### Fase 4: UyumSoft Entegrasyonu (6 saat)
- API bağlantısı
- Ürün import
- Variant yönetimi
- Stok sinkronizasyonu

### Fase 5: Kargo Yönetimi (8 saat)
- Kargo firmaları API
- Label oluşturma
- Invoice oluşturma
- Yazdırma fonksiyonları

### Fase 6: Mail & SMS (4 saat)
- SMTP konfigürasyonu
- SMS sağlayıcı
- Şablon sistemi

### Fase 7: Ayarlar Sayfası (4 saat)
- Tüm entegrasyonlar için
- Zaman ayarları
- Şablon yönetimi

### Fase 8: Queue & Scheduling (4 saat)
- Background jobs
- Cron jobs
- Job logging

### Fase 9: Test & Optimizasyon (4 saat)
- Fonksiyon testleri
- Performance optimization
- Security audits

**TOPLAM: 42-48 saat**

---

## ✅ BAŞARILI OLUNCA KONTROL LISTESI

- [ ] Dashboard Real-time bilgi gösteriyor
- [ ] Shopify → Local DB siparişler senkronize
- [ ] UyumSoft → Shopify ürün senkronizasyonu
- [ ] Stok güncellemeleri otomatik yapılıyor
- [ ] Kargo oluşturma ve yazdırma çalışıyor
- [ ] Email/SMS gönderilişi başarılı
- [ ] Ayarlar sayfası tüm konfigürasyonları yapabiliyor
- [ ] Kullanıcı yönetimi çalışıyor
- [ ] Local test sunucuda stabil çalışıyor
- [ ] Loglar tutuluyar ve görüntülenebiliyor
- [ ] Performance sorunları çözüldü
- [ ] Tüm API bağlantıları güvenli
