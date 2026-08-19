# EtiCart — yerel geliştirmede otomatik senkronizasyon (schedule:work).
# Bu script arka planda sürekli çalışır ve her dakika eticart:cron-run tetikler.
#
# Kullanım:
#   .\scripts\start-scheduler.ps1
#
# Durdurmak için terminal penceresini kapatın veya Ctrl+C.

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $root

if (-not (Test-Path ".\artisan")) {
    Write-Host "Hata: artisan bulunamadi. Proje kokunden calistirin." -ForegroundColor Red
    exit 1
}

Write-Host "EtiCart scheduler baslatiliyor (php artisan schedule:work)..." -ForegroundColor Cyan
Write-Host "Proje: $root"
Write-Host "Durdurmak icin Ctrl+C."
Write-Host ""

php artisan schedule:work
