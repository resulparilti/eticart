@echo off
setlocal
cd /d "%~dp0.."
echo EtiCart scheduler baslatiliyor...
php artisan schedule:work
