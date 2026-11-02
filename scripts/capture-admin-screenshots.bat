@echo off
chcp 65001 > nul
echo ========================================
echo Admin Android স্ক্রিনশট ক্যাপচার টুল
echo ========================================
echo.

cd /d "%~dp0"

REM PowerShell execution policy check and run
powershell -ExecutionPolicy Bypass -File ".\capture-admin-screenshots.ps1"

pause
