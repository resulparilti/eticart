# 🤖 Cursor AI - Hazır Prompt Templates

Bu dosya Cursor'de kopyala-yapıştır yapabileceğin hazır prompt'ları içerir.
Her seferinde yazmanı unutmaman için buradan kopyala!

---

## 📋 TEMEL KURULUM PROMPTS

### Prompt 1: İlk Başlangıç

```
@workspace

Merhaba! EtiCart projesi (Shopify + UyumSoft + Kargo entegre e-ticaret paneli) 
üzerinde çalışacağız.

Proje: Laravel 11 + Bootstrap 5 + MySQL + Redis
Development: Windows local
Tools: PHP 8.2, Composer, Node.js

Şu dosyaları oku ve anla:
1. PROJE_YOL_HARITASI.md (Genel proje yapısı)
2. .cursorrules (Kod standartları)
3. TODO.md (Detaylı görevler)

Sonra şu soruya cevap ver:
"Proje yapısını anladım. FASE 1 ve FASE 2'yi tarif et."

Hazır mısın?
```

---

## 🎨 FASE 2: ADMIN PANELI PROMPTS

### Prompt 2.1: Dashboard Sayfası

```
@workspace

FASE 2: Admin Paneli Tasarımı başlatıyorum.

Bootstrap 5.3 ile minimal, modern, hızlı admin dashboard oluştur:

1. DashboardController oluştur (app/Http/Controllers/)
   - index() method: View return et

2. resources/views/dashboard.blade.php (Ana dashboard)
   - Extends: layouts.app
   - 4 Quick Stat Cards:
     * Orders (Total)
     * Products (Total)
     * Revenue (Today)
     * Shipments (Pending)
   - Recent Orders Table (Son 5 sipariş)
   - Sync Status Card (Shopify, UyumSoft, Kargo)
   - System Health Status

3. Tasarım:
   - Bootstrap grid: container-fluid > row > col
   - Minimal CSS (custom colors: primary #2C3E50, secondary #E67E22)
   - Responsive (xs, md, lg breakpoints)
   - Loading states
   - No jQuery, pure Bootstrap

4. Routes:
   - GET /dashboard → DashboardController@index

Kullan: Blade templates, Laravel helpers, no Alpine.js yet

Hazır? Kodları oluştur ve açıkla.
```

### Prompt 2.2: Navbar & Sidebar

```
@workspace

Navbar ve Sidebar component'leri oluştur:

1. resources/views/components/navbar.blade.php
   - Logo ve proje adı (sol)
   - Quick search (opsiyonel)
   - User menu with dropdown (sağ):
     * Profile
     * Settings
     * Logout

2. resources/views/components/sidebar.blade.php
   - Collapse/expand toggle (mobile friendly)
   - Menu items:
     * Dashboard
     * Orders (Siparişler)
     * Products (Ürünler)
     * Shipments (Kargo)
     * Settings (Ayarlar)
     * Users (Kullanıcılar)
     * Reports (Raporlar)
   - Active state indication
   - Icons (Bootstrap Icons)

3. resources/views/layouts/app.blade.php
   - Main layout template
   - Include navbar ve sidebar
   - Container for content
   - Footer (opsiyonel)

4. resources/css/app.css
   - Bootstrap import
   - Color variables
   - Custom navbar/sidebar styles
   - Responsive adjustments

Tasarım: Bootstrap + minimal CSS, dark mode ready

Hazır? Başla!
```

### Prompt 2.3: Common Components

```
@workspace

Reusable Blade components oluştur (resources/views/components/):

1. table.blade.php
   - Slot: headers (thead)
   - Slot: rows (tbody)
   - Props: striped, hover, pagination
   - Example: @component('table', ['headers' => []])

2. form.blade.php
   - Slot: fields
   - Props: action, method (POST/GET), csrfToken
   - Bootstrap form styling

3. modal.blade.php
   - Slot: title, body, footer
   - Props: id, size (sm, lg)
   - Bootstrap modal structure

4. alert.blade.php
   - Props: type (success/danger/warning/info), message
   - Bootstrap alert styling
   - Auto-dismiss option

5. badge.blade.php
   - Props: type (primary, success, danger), text
   - Bootstrap badge styling

6. loading.blade.php
   - Spinner component
   - Props: size, text

Her component için:
- Parametreler jelas
- Bootstrap classes
- Accessibility attributes
- Documentation

Kodları oluştur!
```

---

## 🗄️ FASE 3: DATABASE & MODELS PROMPTS

### Prompt 3.1: Models ve Migrations

```
@workspace

Database migrations ve Models oluştur:

1. Migrations (database/migrations/):
   - create_settings_table
   - create_uyumsoft_products_table
   - create_shopify_products_table
   - create_shopify_orders_table
   - create_shipments_table

2. Models (app/Models/):
   - Setting (fillable: key, value, category)
   - UyumSoftProduct
   - ShopifyProduct
   - ShopifyOrder
   - ShopifyOrderItem
   - Shipment

3. Relationships:
   - ShopifyOrder hasMany ShopifyOrderItem
   - ShopifyOrder hasMany Shipment
   - UyumSoftProduct hasOne ShopifyProduct

4. Eloquent specifics:
   - Casts (JSON, boolean)
   - Timestamps
   - Mass assignment ($fillable)

Laravel conventions'ı takip et.
Migrations'lar db/migrations/2024_*_*.php format'ında

Hazır? Oluştur!
```

### Prompt 3.2: Seeder ve Factories

```
@workspace

Database seeders oluştur:

1. DatabaseSeeder.php
   - Call UserSeeder
   - Call SettingsSeeder
   - Call CargoCompanySeeder

2. UserSeeder.php
   - Admin user oluştur (admin@eticart.com / password123)

3. SettingsSeeder.php
   - Default settings kaydet:
     * sync_order_interval = 5 (dakika)
     * sync_product_interval = 30
     * sync_stock_interval = 10
     * sync_cargo_interval = 60

4. CargoCompanySeeder.php
   - Aras Kargo
   - MNG Kargo
   - Yurtiçi Kargo
   - PTT

Çalıştır: php artisan db:seed

Dosyaları oluştur!
```

---

## 🔌 FASE 4: SHOPIFY ENTEGRASYONu PROMPTS

### Prompt 4.1: Shopify Service

```
@workspace

Shopify API Service oluştur (app/Services/ShopifyService.php):

Methods:
1. getOrders($limit = 50, $status = 'any')
   - API: GET /orders.json
   - Return: array of orders

2. getOrderDetails($orderId)
   - API: GET /orders/{id}.json
   - Return: single order with items

3. getProducts($limit = 50)
   - API: GET /products.json
   - Return: array of products

4. createProduct($data)
   - API: POST /products.json
   - Params: title, description, price, etc.

5. updateProduct($productId, $data)
   - API: PUT /products/{id}.json

6. updateInventory($variantId, $quantity)
   - API: Update inventory

Error handling:
- HTTP status code kontrol
- Rate limiting (429)
- Network errors (retry logic)
- Log errors

Config (config/services.php):
- shopify.store_url
- shopify.access_token

Env variables (.env):
- SHOPIFY_STORE_URL
- SHOPIFY_ACCESS_TOKEN

Guzzle HTTP client kullan.
Exception handling ve logging include et.

Oluştur!
```

### Prompt 4.2: Order Sync Job

```
@workspace

Order sync job oluştur (app/Jobs/SyncShopifyOrders.php):

Implements ShouldQueue:
1. handle(ShopifyService $service)
   - Fetch orders from Shopify
   - Loop each order:
     * Check if exists in DB (shopify_order_id)
     * If not, create new ShopifyOrder
     * If exists, update status/payment_status
     * Create ShopifyOrderItems
   - Log sync results
   - Save to sync_job_logs

2. Error handling:
   - Try-catch for API errors
   - Retry logic (exponential backoff)
   - Log failed orders

3. Performance:
   - Batch processing (50 orders at a time)
   - Eager load relationships

Config:
- Queue: redis
- Timeout: 600 seconds
- Retry: 3 times

Oluştur!
```

### Prompt 4.3: Order Controller & Views

```
@workspace

Order yönetimi oluştur:

1. OrderController (app/Http/Controllers/OrderController.php):
   - index() → all orders paginated
   - show($id) → order details
   - sync() → manual sync trigger (SyncShopifyOrders job)
   - updateStatus($id, $status) → update fulfillment status

2. resources/views/orders/index.blade.php:
   - Table: Order #, Customer, Total, Status, Date
   - Columns: 
     * Order number (link to show)
     * Customer name
     * Total price
     * Payment status (badge)
     * Fulfillment status (badge)
     * Actions (View, Assign cargo)
   - Filter: Status, Date range
   - Pagination: 50 items/page
   - Bulk actions (opsiyonel)

3. resources/views/orders/show.blade.php:
   - Customer info (name, email, phone)
   - Shipping address
   - Order items table
   - Payment details
   - Fulfillment status
   - Shipment info (if exists)
   - Notes/Timeline
   - Action buttons: Assign cargo, Send message

Routes:
- GET /orders → index
- GET /orders/{id} → show
- POST /orders/sync → sync
- PUT /orders/{id}/status → updateStatus

Bootstrap styling minimal, responsive

Oluştur!
```

---

## 🏷️ FASE 5: UYUMSOFT ENTEGRASYONu PROMPTS

### Prompt 5.1: UyumSoft Service

```
@workspace

UyumSoft API Service oluştur (app/Services/UyumSoftService.php):

Methods:
1. getProducts($limit = 50, $offset = 0)
   - API: GET /products
   - Return: array of products with variants

2. getProductDetails($productId)
   - API: GET /product/{id}
   - Return: single product + variants

3. getStocks()
   - API: GET /stocks
   - Return: all stock levels

4. updateStock($productId, $quantity)
   - API: POST /stocks
   - Update inventory

5. mapProductToShopify($uyumSoftProduct)
   - Convert UyumSoft format to Shopify format
   - Handle variants
   - Handle images
   - Return shopify-compatible array

Authentication:
- HTTP Basic Auth (username:password)
- Base64 encoded

Config (config/services.php):
- uyumsoft.username
- uyumsoft.password
- uyumsoft.base_url

Error handling:
- Auth errors
- API timeouts
- Invalid data

Guzzle kulllan, logging include et

Oluştur!
```

### Prompt 5.2: Product Sync Job

```
@workspace

Product sync job oluştur (app/Jobs/SyncUyumSoftProducts.php):

Implements ShouldQueue:
1. handle(UyumSoftService $uyumSoft, ShopifyService $shopify)
   - Fetch products from UyumSoft
   - Loop each product:
     * Check if exists (uyumsoft_id)
     * Map to Shopify format
     * Create/update on Shopify
     * Save relationship in DB
     * Sync variants
   - Log results

2. Variant handling:
   - Parse UyumSoft variant info
   - Create Shopify variants
   - Match prices and SKUs

3. Images:
   - Download images from UyumSoft (opsiyonel)
   - Upload to Shopify

4. Error handling:
   - Retry failed products
   - Log error messages
   - Continue on error (don't stop entire job)

Queue config
Timeout: 900 seconds (ürünler büyük olabilir)
Retry: 3 times

Oluştur!
```

### Prompt 5.3: Stock Sync Job

```
@workspace

Stock sync job oluştur (app/Jobs/SyncStock.php):

Implements ShouldQueue:
1. handle(UyumSoftService $uyumSoft, ShopifyService $shopify)
   - Get all stocks from UyumSoft
   - Loop each:
     * Find product in DB
     * Get Shopify variant_id
     * Update Shopify inventory
     * Log changes
   - Update last_sync timestamp

2. Batch updates:
   - Group by product
   - Send batch requests to Shopify

3. Logging:
   - Log updated quantities
   - Log failures
   - Save to sync_job_logs

Performance:
- Only sync products marked as synced
- Cache Shopify variant mappings
- Batch size: 100 items

Oluştur!
```

---

## 🚚 FASE 6: KARGO ENTEGRASYONU PROMPTS

### Prompt 6.1: Cargo Service Base

```
@workspace

Kargo abstract service oluştur:

1. app/Services/Cargo/CargoServiceInterface.php
   - Interface with methods:
     * createShipment(array $data): array
     * getTrackingInfo($trackingNumber): array
     * generateLabel($shipmentId): string (file path)
     * generateInvoice($shipmentId): string (file path)
     * cancelShipment($trackingNumber): bool

2. app/Services/Cargo/BasCargoService.php
   - Abstract base class
   - Common methods
   - Error handling

3. Implementations:
   - ArasCargoService
   - MngCargoService
   - YurticiBirligiService
   - PttService

Her provider için:
- API credentials config
- Request/response mapping
- Error handling
- Label generation logic

Service manager:
- app/Services/CargoService.php
- Dispatch to correct provider
- Route by cargo_company_id

Oluştur!
```

### Prompt 6.2: Shipment Controller & Views

```
@workspace

Shipment yönetimi oluştur:

1. ShipmentController (app/Http/Controllers/ShipmentController.php):
   - index() → all shipments paginated
   - create($orderId) → form to create shipment
   - store($orderId) → create shipment + generate label
   - show($id) → shipment details
   - generateLabel($id) → download PDF
   - generateInvoice($id) → download invoice
   - updateStatus($id) → update tracking status

2. resources/views/shipments/index.blade.php:
   - Table: Order #, Customer, Cargo Co, Tracking #, Status
   - Filter: Company, Status, Date
   - Actions: View, Print label, Update status

3. resources/views/shipments/create.blade.php:
   - Auto-fill from order:
     * Customer name/phone/address
   - Select cargo company (radio/select)
   - Weight field
   - Insurance amount
   - Special instructions
   - Create button

4. resources/views/shipments/show.blade.php:
   - Shipment details
   - Tracking number + link
   - Tracking status (updated)
   - Customer info
   - Download buttons: Label, Invoice
   - Print buttons

5. resources/views/shipments/print-label.blade.php
   - A4 PDF layout:
     * QR code + barcode
     * Receiver info (large)
     * Cargo company info
     * Weight
     * Printer margins

6. resources/views/shipments/print-invoice.blade.php
   - PDF layout:
     * Order details
     * Totals
     * Shipping cost
     * Insurance
     * Terms & conditions

Routes:
- GET /shipments → index
- GET /shipments/create/{orderId} → create
- POST /shipments → store
- GET /shipments/{id} → show
- GET /shipments/{id}/label → generateLabel
- GET /shipments/{id}/invoice → generateInvoice

Use DomPDF for PDF generation

Oluştur!
```

---

## 📧 FASE 7: MAIL & SMS PROMPTS

### Prompt 7.1: Mail Service

```
@workspace

Mail service oluştur (app/Services/MailService.php):

Methods:
1. sendOrderConfirmation($order)
   - Template: order-confirmation email
   - Recipient: customer email
   - Variables: {order_id}, {customer_name}, {total}, {items}

2. sendShipmentNotification($shipment)
   - Template: shipment-notification
   - Variables: {order_id}, {tracking_number}, {tracking_url}

3. sendCustom($recipient, $subject, $body)
   - Send arbitrary email

4. sendFromTemplate($recipient, $templateSlug, $data)
   - Load template from DB
   - Replace variables
   - Send

Queue:
- Use Laravel Mail facade
- Queue enabled (redis)
- Retry logic

Config (config/mail.php):
- SMTP credentials from .env
- From address/name

Templates (resources/views/email/):
- order-confirmation.blade.php
- shipment-notification.blade.php
- order-status-update.blade.php

Oluştur!
```

### Prompt 7.2: SMS Service

```
@workspace

SMS service oluştur (app/Services/SmsService.php):

Support sağlayıcıları:
1. Netgsm
2. İletişim.net

Methods:
1. send($phone, $message)
   - Send single SMS
   - Params: phone number (Turkish format), message
   - Return: status (success/failed)

2. sendBulk($phones, $message)
   - Send to multiple recipients

3. sendFromTemplate($phone, $templateSlug, $data)
   - Load template from DB
   - Replace variables
   - Send

4. getBalance()
   - Get account balance

Config (config/services.php):
- sms.provider (netgsm / iletisim)
- sms.api_key
- sms.api_secret
- sms.header (başlık)

Error handling:
- Invalid phone
- Low balance warning
- API errors

Queue:
- Background job (não block)

Oluştur!
```

---

## ⚙️ FASE 8: AYARLAR SAYFASI PROMPTS

### Prompt 8.1: Settings Controller & Views

```
@workspace

Settings yönetimi oluştur:

1. SettingsController (app/Http/Controllers/SettingsController.php):
   - index() → settings overview
   - shopify() → form
   - updateShopify() → save
   - uyumsoft() → form
   - updateUyumsoft() → save
   - cargo() → form
   - updateCargo() → save
   - mail() → form
   - updateMail() → save
   - sms() → form
   - updateSms() → save
   - sync() → form
   - updateSync() → save

2. Views (resources/views/settings/):

   settings/index.blade.php
   - Menu: Shopify, UyumSoft, Cargo, Mail, SMS, Sync, Templates
   - Status indicators (connected/disconnected)

   settings/shopify.blade.php
   - Form fields:
     * Store URL
     * Access Token
     * API Version (read-only: 2024-01)
   - Test Connection button
   - Status indicator

   settings/uyumsoft.blade.php
   - API Username
   - API Password
   - Depo ID
   - Test Connection button

   settings/cargo.blade.php
   - Table: Cargo Company, API Key, Active
   - Edit buttons
   - Each company:
     * API Key
     * API Secret
     * Username/Password (if needed)
     * Active checkbox
     * Default checkbox
     * Test button

   settings/mail.blade.php
   - SMTP Host
   - SMTP Port (dropdown: 465, 587)
   - Username
   - Password
   - From Email
   - From Name
   - Test Email button

   settings/sms.blade.php
   - Provider (dropdown: Netgsm, İletişim.net)
   - API Key
   - API Secret
   - Header/Title
   - Test SMS button

   settings/sync.blade.php
   - Order check interval (dropdown: 1, 5, 10, 15 dakika)
   - Product sync interval
   - Stock sync interval
   - Cargo tracking interval
   - Auto create shipment (checkbox)
   - Auto send tracking (checkbox)
   - Last sync times (read-only)

Form validation:
- Required fields
- URL validation
- Number validation

Redirect after save dengan success message

Oluştur!
```

### Prompt 8.2: Mail & SMS Templates

```
@workspace

Şablon yönetimi oluştur:

1. MailTemplate Model
2. SmsTemplate Model
3. Migration: mail_templates, sms_templates

4. SettingsController methods:
   - mailTemplates() → list
   - editMailTemplate($id) → form
   - updateMailTemplate($id) → save
   - smsTemplates() → list
   - editSmsTemplate($id) → form
   - updateSmsTemplate($id) → save

5. Views (resources/views/settings/templates/):

   mail.blade.php
   - List existing templates
   - Edit button for each
   - Edit form (modal veya new page):
     * Name
     * Subject
     * Body (WYSIWYG editor - CodeMirror/simplemde)
     * Variables helper (info box):
       - {order_id}
       - {customer_name}
       - {customer_email}
       - {order_date}
       - {total_price}
       - {tracking_number}
       - {tracking_url}
     * Test button (send to logged-in user)

   sms.blade.php
   - List templates
   - Edit form:
     * Name
     * Body (textarea)
     * Character count (SMS limit)
     * Variables helper
     * Test button

Seed data:
- Create default templates (order confirmation, shipment notification, etc.)

Oluştur!
```

---

## 👥 FASE 9: KULLANICI YÖNETİMİ PROMPTS

### Prompt 9.1: User Management

```
@workspace

Kullanıcı yönetimi oluştur:

1. User Model update:
   - Add role column (admin, manager, viewer)
   - Add is_active column

2. UserController (app/Http/Controllers/UserController.php):
   - index() → list users
   - create() → form
   - store() → create
   - edit($id) → form
   - update($id) → save
   - deactivate($id) → soft deactivate
   - resetPassword($id) → send reset link

3. Views (resources/views/users/):

   index.blade.php
   - Table: Name, Email, Role, Active, Created
   - Filter: Role, Active status
   - Actions: Edit, Deactivate, Reset Password

   form.blade.php
   - Name (text input)
   - Email (email input)
   - Password (password input) - required on create, optional on edit
   - Role (select: Admin, Manager, Viewer)
   - Active checkbox
   - Save button

Middleware:
- Require authentication
- Require admin role (for user management)

Routes:
- GET /users → index
- GET /users/create → create
- POST /users → store
- GET /users/{id}/edit → edit
- PUT /users/{id} → update
- DELETE /users/{id} → deactivate

Use Spatie Permission package for roles

Oluştur!
```

---

## 📊 FASE 10: RAPORLAR PROMPTS

### Prompt 10.1: Reports

```
@workspace

Raporlar oluştur:

1. ReportController (app/Http/Controllers/ReportController.php):
   - sales() → sales report view
   - getSalesData($dateFrom, $dateTo) → API endpoint
   - syncLogs() → sync logs view
   - systemLogs() → system errors view

2. ReportService (app/Services/ReportService.php):
   - getSalesReport($dateFrom, $dateTo)
     * Total revenue
     * Order count
     * Average order value
     * By day chart data
   - getSyncReport($dateFrom, $dateTo)
     * Shopify sync status
     * UyumSoft sync status
     * Success/failure count
   - getSystemLogs()
     * Recent errors
     * API failures
     * Performance issues

3. Views (resources/views/reports/):

   sales.blade.php
   - Date range picker (from/to)
   - Chart: Revenue by day (Chart.js)
   - Table: Daily breakdown
   - Metrics: Total, Average, etc.
   - Export button (CSV)

   sync-logs.blade.php
   - Table: Sync type, Date, Status, Count, Errors
   - Filter: Type (shopify/uyumsoft), Status, Date
   - Details button (modal with full log)
   - Export button

   system-logs.blade.php
   - Recent error log
   - Filter: Type, Severity
   - Clear old logs button

Use Chart.js for graphs
Export to CSV/PDF capability

Oluştur!
```

---

## 🔄 FASE 11: QUEUE & SCHEDULER PROMPTS

### Prompt 11.1: Scheduler Setup

```
@workspace

Scheduler ve Queue setup (app/Console/Kernel.php):

```php
protected function schedule(Schedule $schedule)
{
    // Every minute
    $schedule->job(new SyncShopifyOrders)
        ->everyMinute()
        ->withoutOverlapping();

    // Every 10 minutes
    $schedule->job(new SyncStock)
        ->everyTenMinutes()
        ->withoutOverlapping();

    // Every 30 minutes
    $schedule->job(new SyncUyumSoftProducts)
        ->everyThirtyMinutes()
        ->withoutOverlapping();

    // Every hour
    $schedule->job(new UpdateCargoTracking)
        ->hourly()
        ->withoutOverlapping();

    // Daily at 2 AM
    $schedule->job(new GenerateDailyReport)
        ->dailyAt('02:00')
        ->withoutOverlapping();

    // Every 4 hours
    $schedule->job(new CleanupOldLogs)
        ->everyFourHours()
        ->withoutOverlapping();
}
```

Queue config (config/queue.php):
- Driver: redis
- Connection: redis
- Timeout: 600

Oluştur!
```

### Prompt 11.2: Queue Monitor View

```
@workspace

Queue monitoring view oluştur (resources/views/admin/queue-status.blade.php):

- Pending jobs count
- Failed jobs count
- Recent jobs table (last 20)
- Failed jobs table
- Retry failed button
- Clear old jobs button
- Auto-refresh (15 saniye)

Sidebar'da link: Queue Status

Oluştur!
```

---

## 🧪 FASE 12: TESTING PROMPTS

### Prompt 12.1: Unit Tests

```
@workspace

Unit tests oluştur (tests/Unit/Services/):

1. ShopifyServiceTest.php
   - Mock Guzzle client
   - Test getOrders()
   - Test getProducts()
   - Test error handling

2. UyumSoftServiceTest.php
   - Mock API responses
   - Test product fetching
   - Test stock updates

3. CargoServiceTest.php
   - Test shipment creation
   - Test label generation
   - Test tracking

Run tests: php artisan test

Oluştur!
```

### Prompt 12.2: Feature Tests

```
@workspace

Feature tests oluştur (tests/Feature/):

1. OrderControllerTest.php
   - Test auth required
   - Test index listing
   - Test show detail
   - Test sync trigger

2. ProductControllerTest.php
   - Test sync to Shopify
   - Test update operations

3. ShipmentControllerTest.php
   - Test creation
   - Test label generation

Database setup (tests/TestCase.php):
- SQLite in-memory
- Migration setup
- Database reset between tests

Oluştur!
```

---

## 🎯 HATA GİDERME PROMPTS

### Prompt: Hata Debuggin

```
@workspace

Şu hatayı alıyorum:

[Error message buraya]

Dosya: [file path]
Satır: [line number]
Stack trace:
[paste stack trace]

Neden olmuş olabilir ve nasıl çözerim?
```

### Prompt: Performance Sorunu

```
@workspace

Şu sayfa yavaş açılıyor: [page name]
Açılış süresi: [time] saniye

Sorunu teşhis et:
1. Database queries (N+1?)
2. Cache issues
3. Asset loading
4. API calls

Debug et ve optimize et.
```

---

## 📝 BELGELENDİRME PROMPTS

### Prompt: Dosya Açıklamması

```
@workspace

Şu dosyayı oluşturdum: app/Services/ShopifyService.php

Lütfen:
1. Dosyayı kontrol et
2. Error handling yeterli mi?
3. Type hints var mı?
4. PHPDoc comments ekle
5. Best practices'ı takip ediyor mu?

Review ve iyileştir.
```

---

## 🚀 DEPLOYMENT PROMPTS

### Prompt: Production Hazırlığı

```
@workspace

Production sunucusuna deploy öncesi checklist:

1. Security
   - API credentials encrypted
   - HTTPS configured
   - CORS settings
   - Rate limiting

2. Performance
   - Config cache
   - Route cache
   - View cache
   - Asset minification

3. Database
   - Migrations run
   - Indexes created
   - Backups configured

4. Monitoring
   - Error logging
   - Performance monitoring
   - Health checks

Tüm kontrolleri listele ve açıkla.
```

---

## ✅ KONTROL LİSTESİ PROMPTS

### Prompt: Fase Tamamlama Kontrol

```
@workspace

FASE [X] tamamlandı. Kontrol et:

✓ Tüm dosyalar oluşturuldu
✓ Kodlar çalışıyor
✓ Database migrate passed
✓ Local test başarılı
✓ Responsive design kontrol
✓ Error handling var
✓ Logging yapılıyor
✓ Performance iyiy
✓ Security kuralları
✓ Documentation complete

Tüm kontrolleri yap ve raporla.
```

---

## 💡 ÖNERİ: PROMPT ŞABLONU

Her önemli task'ta kullan:

```
@workspace @[relevant_file.php]

TASK: [Basit görev açıklaması]

DETAYLAR:
- Ne yapacak: [açıkla]
- Nereye: [dosya path]
- Nasıl: [teknoloji/method]

GEREKLER:
- [ ] Requirement 1
- [ ] Requirement 2
- [ ] Error handling
- [ ] Logging
- [ ] Testing

KONTROL:
- Local test et
- Hata varsa debug et
- Documentation güncelle

Hazır? Başla!
```

---

## 📞 HIZLI REFERENCE

```
.cursorrules      → Kod standartları
PROJE_YOL_HARITASI.md → Tüm detaylar
TODO.md           → Görev listesi
OZET_HIZLI_REFERANS.md → Quick ref

Cursor Commands:
- @workspace      → Proje yapısı
- @package.json   → Dependencies
- @.env.example   → Environment
- New Chat        → Ctrl+Shift+P
```

---

**Not:** Bu dosyayı her aşamada referans al.
Spesifik prompt yazarsan Cursor daha iyi sonuç verir!

**Başarılar! 🚀**
