# EtiCart'i Shopify'in kabul edecegi HTTPS adrese acar (Cloudflare quick tunnel).
# Onkosul: php artisan serve (127.0.0.1:8000) calisiyor olmali.
# cloudflared yoksa: https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/

$ErrorActionPreference = "Stop"
$local = "http://127.0.0.1:8000"

Write-Host "Yerel sunucu kontrol ediliyor: $local"
try {
    Invoke-WebRequest -Uri $local -UseBasicParsing -TimeoutSec 5 | Out-Null
} catch {
    Write-Host "ONEMLI: Once 'php artisan serve' calistirin."
    exit 1
}

$cloudflared = Get-Command cloudflared -ErrorAction SilentlyContinue
if (-not $cloudflared) {
    Write-Host "cloudflared bulunamadi."
    Write-Host "Kurulum: winget install --id Cloudflare.cloudflared"
    Write-Host "veya ngrok: ngrok http 8000"
    exit 1
}

Write-Host "Cloudflare tunnel baslatiliyor. Cikan https://*.trycloudflare.com adresini"
Write-Host ".env -> SHOPIFY_APP_URL olarak yazin (veya Ayarlar > Shopify)."
Write-Host ""

& cloudflared tunnel --url $local
