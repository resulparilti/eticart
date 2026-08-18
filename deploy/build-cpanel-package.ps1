# EtiCart — cPanel dağıtım paketi (vendor + public/build, ZIP içi Unix 755)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
if (-not (Test-Path (Join-Path $Root "artisan"))) {
    throw "Proje kökü bulunamadı: $Root"
}

Set-Location $Root

function Assert-FileExists([string]$Path, [string]$Hint) {
    if (-not (Test-Path $Path)) {
        throw "Eksik dosya: $Path`n$Hint"
    }
}

Write-Host "==> On kontroller" -ForegroundColor Cyan

$requiredSources = @(
    @{ Path = "public\setup.php"; Hint = "Kurulum sihirbazi gerekli." },
    @{ Path = "public\fix-permissions.php"; Hint = "Izin duzeltme scripti gerekli." },
    @{ Path = "public\health.php"; Hint = "Teshis scripti gerekli." },
    @{ Path = "public\clear-cache.php"; Hint = "Onbellek temizleme scripti gerekli." },
    @{ Path = "public\cron-status.php"; Hint = "Cron teshis scripti gerekli." },
    @{ Path = "app\Install\WebInstaller.php"; Hint = "Web kurulum sinifi gerekli." },
    @{ Path = "app\Console\Commands\CronRun.php"; Hint = "eticart:cron-run gerekli." },
    @{ Path = "resources\views\components\production-assets.blade.php"; Hint = "Vite fallback component gerekli." },
    @{ Path = "deploy\cpanel\env.production.example"; Hint = "Env sablonu gerekli." },
    @{ Path = "deploy\zip_unix_755.py"; Hint = "755 ZIP scripti gerekli." }
)
foreach ($item in $requiredSources) {
    Assert-FileExists (Join-Path $Root $item.Path) $item.Hint
}

Write-Host "==> npm build (public/build)" -ForegroundColor Cyan
if (Get-Command npm -ErrorAction SilentlyContinue) {
    npm run build
} else {
    Write-Warning "npm yok - public/build onceden olusturulmus olmali."
}
Assert-FileExists (Join-Path $Root "public\build\manifest.json") "npm run build calistirin."

if (-not (Test-Path (Join-Path $Root "vendor\autoload.php"))) {
    throw "vendor/autoload.php yok. Once composer install calistirin."
}

$distDir = Join-Path $Root "deploy\dist"
$staging = Join-Path $distDir "eticart-cpanel-staging"
if (Test-Path $staging) {
    Remove-Item $staging -Recurse -Force
}
New-Item -ItemType Directory -Path $staging -Force | Out-Null

Write-Host "==> Dosyalar kopyalaniyor ( .env HARIC )..." -ForegroundColor Cyan

$excludeNames = @(
    ".git", "node_modules", ".cursor", "tests", ".env", ".github",
    "phpunit.xml", ".phpunit.result.cache", ".phpunit.cache",
    "deploy", "storage"
)

Get-ChildItem -Path $Root -Force | ForEach-Object {
    $name = $_.Name
    if ($excludeNames -contains $name) { return }
    Copy-Item -Path $_.FullName -Destination (Join-Path $staging $name) -Recurse -Force
}

$storageDirs = @(
    "storage\app\public",
    "storage\framework\cache\data",
    "storage\framework\sessions",
    "storage\framework\views",
    "storage\logs"
)
foreach ($dir in $storageDirs) {
    $full = Join-Path $staging $dir
    New-Item -ItemType Directory -Path $full -Force | Out-Null
    Set-Content -Path (Join-Path $full ".gitkeep") -Value "" -Encoding UTF8
}

$bootstrapCache = Join-Path $staging "bootstrap\cache"
New-Item -ItemType Directory -Path $bootstrapCache -Force | Out-Null
Get-ChildItem -Path $bootstrapCache -Filter "*.php" -ErrorAction SilentlyContinue | Remove-Item -Force
Set-Content -Path (Join-Path $bootstrapCache ".gitignore") -Value "*`n!.gitignore" -Encoding UTF8

Copy-Item (Join-Path $Root "deploy\CPANEL-KURULUM.md") (Join-Path $staging "KURULUM-CPANEL.md") -Force
Copy-Item (Join-Path $Root "deploy\cpanel\KURULUM-HIZLI.txt") (Join-Path $staging "KURULUM-HIZLI.txt") -Force
Copy-Item (Join-Path $Root "deploy\cpanel\OKU-BENI.txt") (Join-Path $staging "OKU-BENI.txt") -Force
Copy-Item (Join-Path $Root "deploy\cpanel\env.production.example") (Join-Path $staging "env.production.example") -Force
Copy-Item (Join-Path $Root "deploy\cpanel\public_html-index.php.example") (Join-Path $staging "public_html-index.php.example") -Force

if (Get-Command composer -ErrorAction SilentlyContinue) {
    Write-Host "==> staging: composer install --no-dev" -ForegroundColor Cyan
    Push-Location $staging
    try {
        composer install --no-dev --optimize-autoloader --no-interaction --no-progress
    } finally {
        Pop-Location
    }
} else {
    Write-Warning "composer yok; mevcut vendor kopyasi kullanilacak."
}

Write-Host "==> Paket dogrulama..." -ForegroundColor Cyan
$requiredInZip = @(
    "vendor\autoload.php",
    "public\build\manifest.json",
    "public\build\assets",
    "public\setup.php",
    "public\fix-permissions.php",
    "public\health.php",
    "public\clear-cache.php",
    "public\cron-status.php",
    "public\index.php",
    "public\.htaccess",
    "app\Install\WebInstaller.php",
    "app\Console\Commands\CronRun.php",
    "app\Console\Kernel.php",
    "database\migrations\2026_08_13_120000_create_user_workspace_tables.php",
    "resources\views\components\production-assets.blade.php",
    "env.production.example",
    "OKU-BENI.txt"
)
$missing = @()
foreach ($rel in $requiredInZip) {
    if (-not (Test-Path (Join-Path $staging $rel))) {
        $missing += $rel
    }
}
if ($missing.Count -gt 0) {
    throw "Paket eksik:`n - $($missing -join "`n - ")"
}

$date = Get-Date -Format "yyyyMMdd-HHmm"
$zipName = "eticart-cpanel-$date.zip"
$zipPath = Join-Path $distDir $zipName
$desktopZip = Join-Path ([Environment]::GetFolderPath("Desktop")) $zipName

Write-Host "==> ZIP (Unix 755): $zipPath" -ForegroundColor Cyan
$py = Get-Command python -ErrorAction SilentlyContinue
if (-not $py) { $py = Get-Command py -ErrorAction SilentlyContinue }
if (-not $py) { throw "Python bulunamadi. ZIP 755 izinleri icin python gerekir." }

& $py.Source (Join-Path $Root "deploy\zip_unix_755.py") $staging $zipPath
if ($LASTEXITCODE -ne 0) {
    throw "ZIP olusturulamadi."
}

Copy-Item $zipPath $desktopZip -Force
$sizeMb = [math]::Round((Get-Item $zipPath).Length / 1MB, 1)
Remove-Item $staging -Recurse -Force

Write-Host ""
Write-Host "Hazir: $zipPath ($sizeMb MB)" -ForegroundColor Green
Write-Host "Masaustu: $desktopZip" -ForegroundColor Green
Write-Host ""
Write-Host "GUNCELLEME (mevcut cPanel):" -ForegroundColor Yellow
Write-Host "  1. Extract - .env uzerine YAZMAYIN" -ForegroundColor Yellow
Write-Host "  2. fix-permissions.php?run=1" -ForegroundColor Yellow
Write-Host "  3. clear-cache.php?run=1" -ForegroundColor Yellow
Write-Host "  4. setup.php CALISTIRMAYIN" -ForegroundColor Yellow
