# 🚀 EtiCart - Başlangıç Rehberi

## 📋 Proje Özeti

**EtiCart** = Shopify + UyumSoft + Kargo + Mail/SMS entegre eden minimal, modern, hızlı e-ticaret yönetim paneli.

---

## 💻 Gereksinimler

### Local Development Kurulumu İçin:

```
✅ PHP 8.2+ (php.exe PATH'de olmalı)
✅ MySQL 8.0+ (Komut satırından mysql erişilebilmeli)
✅ Redis 7.0+ (Windows'ta: Windows Subsystem for Linux veya Docker)
✅ Node.js 18+ (npm ile)
✅ Composer (PHP dependency manager)
✅ Git (versiyon kontrolü)
✅ VS Code + Cursor extension (Code editor)
```

### Windows'ta Kurulum:

```bash
# PHP 8.2 indir ve C:\php8.2 klasörüne koy
# Path'e C:\php8.2 ekle

# MySQL 8.0 Community Server indir ve kur
# MySQL yönetim aracı: MySQL Workbench

# Redis Windows'ta:
# Seçenek 1: Windows Subsystem for Linux (WSL2) + Redis
# Seçenek 2: Docker Desktop + Redis container
# Seçenek 3: MemoryCache as Redis alternative (development)

# Node.js indir: https://nodejs.org (18+ LTS)

# Composer indir: https://getcomposer.org

# Verify kurulumlar:
php -v
mysql --version
redis-cli --version (or skip for WSL)
node -v
npm -v
composer -v
```

---

## 📁 Proje Klasör Yapısı

```
eticart/
├── app/                    → Application logic
├── resources/              → Views & assets
├── database/               → Migrations & seeds
├── routes/                 → Web & API routes
├── storage/                → Logs, uploads, cache
├── public/                 → Web root
├── config/                 → Configuration files
├── .env                    → Environment variables
├── .env.example            → Example env
├── composer.json           → PHP dependencies
├── package.json            → Node dependencies
├── PROJE_YOL_HARITASI.md  → Proje detayları
├── .cursorrules            → Cursor AI kuralları
└── TODO.md                 → Detaylı görevler
```

---

## 🎯 ADIM 1: PROJE KLAÖRÜNÜzÜ OLUŞTUR

```bash
# Komut satırında:
cd C:\Projects\  (veya istediğin klasör)

# Laravel yeni projesi oluştur
composer create-project laravel/laravel eticart

# Proje klasörüne gir
cd eticart

# Package.json dependencies yükle
npm install

# Verify kurulumu
php artisan --version
```

**Çıktı:** Laravel 11.x

---

## 🎯 ADIM 2: .env DOSYASINI AYARLA

### `.env.example`'dan `.env` oluştur:

```bash
cp .env.example .env
```

### `.env` dosyasını düzenle (Notepad++):

```env
APP_NAME=EtiCart
APP_ENV=local
APP_KEY=                          # php artisan key:generate ile doldur
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eticart              # MySQL'de oluştur
DB_USERNAME=root
DB_PASSWORD=                      # MySQL root şifresi

# Cache & Queue
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=cookie

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Mail (Gmail örneği)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=app-password-here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="EtiCart"
```

### APP_KEY Oluştur:

```bash
php artisan key:generate
# Çıktı: Application key set successfully.
```

---

## 🎯 ADIM 3: DATABASE OLUŞTUR

### MySQL'de database oluştur:

```bash
# MySQL'e bağlan
mysql -u root -p

# Database oluştur
CREATE DATABASE eticart;
CREATE DATABASE eticart_test;

# Exit
exit;
```

### Migrations çalıştır:

```bash
php artisan migrate:fresh

# Çıktı: Migration table created successfully.
```

---

## 🎯 ADIM 4: AUTHENTICATION KURULUMU

### Breeze (Minimal Auth) yükle:

```bash
php artisan breeze:install blade

# Compile assets
npm run build
```

### Database'e admin user ekle:

```bash
# Database seeder çalıştır (daha sonra oluşturacağız)
# Şimdilik manuel olarak:

php artisan tinker

# Tinker console'da:
User::create([
    'name' => 'Admin',
    'email' => 'admin@eticart.com',
    'password' => bcrypt('password123')
]);

exit
```

---

## 🎯 ADIM 5: TEMEL PACKAGES YÜKLE

```bash
# Backend packages
composer require guzzlehttp/guzzle redis intervention/image barryvdh/laravel-dompdf spatie/laravel-permission

# Frontend packages
npm install bootstrap@5.3.0 bootstrap-icons alpinejs
npm install --save-dev @vitejs/plugin-vue

# Config yayınla
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

---

## 🎯 ADIM 6: TEMEL KLASÖRLERI OLUŞTUR

```bash
# Windows PowerShell'de:
mkdir app/Services
mkdir app/Jobs
mkdir app/Events
mkdir resources/views/components
mkdir resources/views/layouts
mkdir resources/views/orders
mkdir resources/views/products
mkdir resources/views/shipments
mkdir resources/views/settings
mkdir resources/views/users
mkdir resources/views/reports
mkdir storage/cargo_labels
mkdir storage/invoices
mkdir storage/imports
```

---

## 🎯 ADIM 7: CURSOR AYARLARINI YAPLA

### Cursor'ü aç ve proje klasörünü aç:

```
File → Open Folder → C:\Projects\eticart
```

### `.cursorrules` dosyasını proje root'una kopyala:

```
Mevcut: outputs/.cursorrules
Yapıştır: eticart/.cursorrules
```

### Cursor Settings (Ctrl + ,) → Yapılandır:

```
✅ "Auto-apply suggestions" → ON
✅ "Auto-save" → ON
✅ "Language" → Türkçe
```

---

## 🎯 ADIM 8: LOCAL SERVER'I BAŞLAT

### Terminal 1: Laravel Development Server

```bash
cd C:\Projects\eticart

php artisan serve
# Çıktı: Server running on [http://127.0.0.1:8000]
```

### Terminal 2: Queue Worker

```bash
cd C:\Projects\eticart

php artisan queue:work
# Bekleme modunda, jobları işleyecek
```

### Terminal 3: Redis (Windows'ta)

```bash
# WSL2 kullanıyorsan:
wsl redis-server

# Veya Docker:
docker run -d -p 6379:6379 redis:latest

# Veya Redis Windows kurulumu varsa:
redis-server.exe
```

### Tarayıcıyı Aç:

```
http://localhost:8000
```

**Görülmesi gereken:** Laravel Breeze login sayfası

---

## 🎯 ADIM 9: İLK AYARLAR

### Admin Panel Oluştur:

```
1. Register'a tıkla
2. Admin hesabı oluştur (admin@eticart.com / password123)
3. Login yap
```

### Dashboard'u Kontrol Et:

```
GET http://localhost:8000/dashboard
→ Boş sayfa (normal, henüz implement etmedik)
```

---

## 🎯 ADIM 10: CURSOR İLE ÇALIŞMAYA BAŞLA

### Cursor'de komut palet aç (Ctrl + Shift + P):

```
> Cursor: New Chat
```

### Şu prompt'u gir:

```
Merhaba! EtiCart projesi üzerinde çalışacağız. 

Proje: Laravel 11 + Bootstrap 5 + MySQL + Redis
Amaç: Shopify + UyumSoft + Kargo entegre e-ticaret paneli

Başlangıç adımları:
1. @workspace ile proje yapısını kontrol et
2. PROJE_YOL_HARITASI.md'yi oku
3. .cursorrules'u uygula
4. TODO.md'den FASE 2'yi başlat (Dashboard & Admin UI)

Hazır mısın?
```

### Cursor Otomatikman Şunları Yapacak:

```
✅ Workspace taraması
✅ Dosya yapısını anlama
✅ Rules'lara uygun kod yazma
✅ Automatic suggestions
✅ Code generation
✅ Error fixes
```

---

## 📊 Sırada Ne Var?

### Cursor'de devam et:

**FASE 2: Admin Paneli Tasarımı (4 saat)**

```
✅ Bootstrap teması özelleştir
✅ Navbar & Sidebar oluştur
✅ Dashboard widgets
✅ Common components
✅ CSS & JavaScript setup
```

### Komutlar:

```bash
# Asset compilation (CSS/JS)
npm run dev    # Development (watch mode)
npm run build  # Production (minified)

# Laravel commands
php artisan make:controller DashboardController
php artisan make:model Setting -m
php artisan migrate
php artisan tinker
```

---

## 🔧 SORUN GİDERME

### Laravel serve çalışmıyor?

```bash
# Port değiştir:
php artisan serve --port=8001

# Firewall sorunuysa:
php artisan serve --host=0.0.0.0
```

### Database bağlantısı başarısız?

```bash
# MySQL çalışıyor mu?
mysql -u root -p

# Database var mı?
SHOW DATABASES;

# .env kontrol et (DB_HOST, DB_USERNAME, DB_PASSWORD)
```

### Redis bağlantısı başarısız?

```bash
# Redis çalışıyor mu?
redis-cli ping
# Çıktı: PONG

# Redis'i başlat (Windows):
redis-server.exe

# Docker'da:
docker ps | findstr redis
```

### Node modules sorunu?

```bash
# npm cache temizle
npm cache clean --force

# node_modules sil ve tekrar yükle
rm -r node_modules package-lock.json
npm install
```

### Migrations sorunu?

```bash
# Reset et
php artisan migrate:fresh

# Rollback et
php artisan migrate:rollback

# Fresh seed ile
php artisan migrate:fresh --seed
```

---

## 📚 DOSYALARI NEREDE BULACAĞIM?

```
📄 PROJE_YOL_HARITASI.md
   → Tüm özellikler, veritabanı şeması, menü yapısı

📄 .cursorrules
   → Cursor AI kuralları, kod standartları

📄 TODO.md
   → 13 Fase, her fase için detaylı görevler

📄 Bu dosya (01-BASLANGIC_REHBERI.md)
   → Kurulum ve başlangıç adımları

👉 Proje klasöründe tüm dosyalar olacak
```

---

## ✅ BAŞARIYLA KURDUM, ŞİMDİ NE YAPIYIM?

### 1. Cursor'de yeni chat aç

```
Ctrl + Shift + P → Cursor: New Chat
```

### 2. Prompt gir:

```
@workspace

TODO.md dosyasını oku. FASE 2 başlat.
Dashboard sayfası oluştur:
- Bootstrap minimal modern tasarım
- Navbar (logo, user menu)
- Sidebar (menü)
- Dashboard widgets:
  - Son 5 sipariş
  - Senkronizasyon durumu
  - Quick stats (4x card)
  - System health

Blade templates kullan, minimal CSS.
Kodları app/ klasöründe oluştur.
```

### 3. Cursor kodlar ve oluşturur, sen gözlemle

```
✅ Controllers
✅ Views (Blade)
✅ Routes
✅ CSS customization
```

### 4. Sonra local'de test et:

```bash
npm run dev         # CSS/JS compile
php artisan serve   # Server

http://localhost:8000/dashboard
```

---

## 🎯 TOPLAM PROJE AKIŞI

```
1️⃣ KURULUM (Bu adımlar) → ✅ Tamamlandı
   ↓
2️⃣ ADMIN UI (Cursor, FASE 2) → Başla
   ↓
3️⃣ DATABASE & MODELS (Cursor, FASE 3)
   ↓
4️⃣ SHOPIFY ENTEGRASYON (Cursor, FASE 4-5)
   ↓
5️⃣ KARGO YÖNETİMİ (Cursor, FASE 6)
   ↓
6️⃣ MAIL & SMS (Cursor, FASE 7)
   ↓
7️⃣ AYARLAR (Cursor, FASE 8)
   ↓
8️⃣ KULLANICILAR & RAPORLAR (Cursor, FASE 9-10)
   ↓
9️⃣ QUEUE & SCHEDULER (Cursor, FASE 11)
   ↓
🔟 TESTING (Cursor, FASE 12)
   ↓
1️⃣1️⃣ LOCAL TEST (Manual, FASE 13)
   ↓
1️⃣2️⃣ PRODUCTION HAZIRLIK (FASE 14)
   ↓
🚀 SUNUCU'YA DEPLOY
```

**Toplam: 40-50 saat**

---

## 📞 YARDIMA İHTİYACI VARSA

### Cursor Chat'te sor:

```
"Şu hatayı alıyorum: [error message]"
"Şu klasör yapısını oluştursam mı? [dosya adları]"
"Sonraki adım ne yapmalı? [mevcut durum]"
```

### Kendine sor:

```
1. Hata ne? (Error message)
2. Nerede hata? (File + Line)
3. Ne yapmak istiyordum? (Intent)
4. Daha önce çalıştı mı? (Was it working?)
5. Ne değişti? (Recent changes)
```

---

## 🎓 ÖNEMLİ BİLGİLER

### Cursor Kullanmanın Sırrı:

```
✅ Spesifik ve detaylı prompt yaz
✅ @workspace, @file.php kullan
✅ Code review sonuçlarını oku
✅ Automatic suggestions'u kabul et
✅ Errors'dan öğren
❌ Blindly copy-paste yapma
❌ Tüm kodu kendisi yazsın bekleme
```

### Laravel Öğrenilecek Konseptler:

```
✅ Models & Migrations
✅ Controllers & Routes
✅ Blade Templates
✅ Service Layer
✅ Jobs & Queues
✅ API Integration
✅ Security (Auth, CSRF, XSS)
```

### Entegrasyon Kompleksitesi:

```
Shopify API      → En basit (REST + GraphQL)
UyumSoft API     → Orta zorluk (SOAP/REST)
Kargo APIs       → Zor (4x farklı provider)
Mail & SMS       → Kolay (SMTP + API)
```

---

## 🚀 HADI BAŞLAYALIM!

```
1. Tüm adımları sırayla tamamla
2. Terminal'leri açık tut (3 adet)
3. Cursor açık tut
4. TODO.md'yi kontrol et
5. FASE 2'den başla
6. Her adımda local test et
```

**Başarılar! 🎉**

---

**Sorular?** Cursor chat'te sor.
**Hata?** Stack trace'i paylaş.
**İlerleme?** Tamamlanan fase'nin yanına ✅ koy.

**Next:** FASE 2 başladığında `02-FASE2_ADMIN_UI.md` oluşturacağım.
