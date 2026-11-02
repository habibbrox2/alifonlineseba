# Admin Android App Screenshot Capture Script
# Takes screenshots of current screen and saves to screenshorts/admin-android

param(
    [string]$OutputDir = "G:\Web\allseba-online\screenshorts\admin-android",
    [string]$PackageName = "com.aliftools.admin"
)

# Create output directory
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
    Write-Host "📁 তৈরি করা হয়েছে: $OutputDir" -ForegroundColor Green
}

# Check adb
$adb = Get-Command adb -ErrorAction SilentlyContinue
if (-not $adb) {
    Write-Host "❌ adb পাওয়া যায়নি। Android Platform Tools PATH-এ যোগ করুন অথবা ANDROID_HOME সেট করুন।" -ForegroundColor Red
    exit 1
}

Write-Host "🔍 সংযুক্ত ডিভাইস খুঁজছি..." -ForegroundColor Cyan
$devices = adb devices | Select-String -Pattern "device$"
if ($devices.Count -eq 0) {
    Write-Host "❌ কোনো ডিভাইস/ইমুলেটর সংযুক্ত নেই।" -ForegroundColor Red
    Write-Host "   USB ডিবাগিং চালু করুন এবং ডিভাইস কানেক্ট করুন।" -ForegroundColor Yellow
    exit 1
}

Write-Host "✅ $($devices.Count)টি ডিভাইস পাওয়া গেছে" -ForegroundColor Green
Write-Host ""

# Try to find running activity
Write-Host "📱 বর্তমান অ্যাকটিভিটি চেক করা হচ্ছে..." -ForegroundColor Cyan
$currentActivity = adb shell "dumpsys activity activities | grep -E 'mResumedActivity|topResumedActivity' | head -1" 2>$null
Write-Host "   $currentActivity" -ForegroundColor Gray
Write-Host ""

# Counter
$counter = (Get-ChildItem -Path $OutputDir -Filter "*.png" | Measure-Object).Count + 1

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Admin Android স্ক্রিনশট ক্যাপচার টুল" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "ব্যবহারবিধি:" -ForegroundColor Yellow
Write-Host "1. অ্যাপে প্রয়োজনীয় স্ক্রিনে যান" -ForegroundColor White
Write-Host "2. এই উইন্ডোতে Enter চাপুন স্ক্রিনশট নিতে" -ForegroundColor White
Write-Host "3. 'q' লিখে Enter চাপুন বের হওয়ার জন্য" -ForegroundColor White
Write-Host "4. স্ক্রিনশট এখানে সেভ হবে: $OutputDir" -ForegroundColor White
Write-Host ""
Write-Host "টিপস:" -ForegroundColor Yellow
Write-Host "- প্রতিটি স্ক্রিনের জন্য আলাদা আলাদা নাম দিতে চাইলে স্ক্রিনশট নেওয়ার পর ফাইলের নাম পরিবর্তন করতে পারবেন" -ForegroundColor Gray
Write-Host "- স্বয়ংক্রিয়ভাবে অ্যাকটিভিটি নাম ব্যবহার করতে পারবেন" -ForegroundColor Gray
Write-Host ""

while ($true) {
    $input = Read-Host "স্ক্রিনশট নিতে Enter চাপুন (বের হতে 'q' লিখুন)"
    if ($input -eq "q" -or $input -eq "Q") {
        break
    }

    $timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
    $filename = "screen_{0:D3}_{1}.png" -f $counter, $timestamp
    $filepath = Join-Path $OutputDir $filename

    Write-Host "📸 স্ক্রিনশট নিচ্ছি..." -ForegroundColor Cyan

    # Take screenshot directly to PC
    adb exec-out screencap -p > $filepath

    if (Test-Path $filepath) {
        $size = (Get-Item $filepath).Length
        Write-Host "✅ সেভ হয়েছে: $filename ($([math]::Round($size/1KB, 2)) KB)" -ForegroundColor Green
        $counter++
    } else {
        Write-Host "❌ স্ক্রিনশট নিতে ব্যর্থ হয়েছে" -ForegroundColor Red
    }

    Write-Host ""
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "ক্যাপচার শেষ। মোট $($counter-1)টি স্ক্রিনশট নেয়া হয়েছে" -ForegroundColor Green
Write-Host "ফোল্ডার: $OutputDir" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
