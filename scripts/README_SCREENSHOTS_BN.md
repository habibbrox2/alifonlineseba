# Admin Android App স্ক্রিনশট ক্যাপচার গাইড

Admin Android অ্যাপের প্রতিটি স্ক্রিনের স্ক্রিনশট নিয়ে `screenshorts/admin-android/` ফোল্ডারে সংরক্ষণ করার জন্য এই টুলগুলো তৈরি করা হয়েছে।

## প্রয়োজনীয়তা

1. **Android Platform Tools (adb)** ইনস্টল থাকতে হবে
   - Windows: `C:\platform-tools\` এ adb.exe থাকতে হবে অথবা PATH এ যোগ করতে হবে
2. **ডিভাইস/ইমুলেটর সংযুক্ত** থাকতে হবে
   - USB ডিবাগিং চালু থাকতে হবে
   - `adb devices` কমান্ডে ডিভাইস দেখা যাবে

## ফোল্ডার কাঠামো

```
G:\Web\allseba-online\
├── screenshorts\
│   └── admin-android\          # স্ক্রিনশট এখানে সেভ হবে
└── scripts\
    ├── capture-admin-screenshots.ps1   # PowerShell স্ক্রিপ্ট (সুপারিশকৃত)
    ├── capture_admin_screenshots.py    # Python স্ক্রিপ্ট
    └── README_SCREENSHOTS_BN.md       # এই গাইড
```

## ব্যবহার পদ্ধতি

### পদ্ধতি ১: PowerShell স্ক্রিপ্ট (সহজ ও দ্রুত)

১. PowerShell খুলুন `scripts/` ফোল্ডারে

```powershell
cd G:\Web\allseba-online\scripts
```

২. স্ক্রিপ্ট রান করুন

```powershell
.\capture-admin-screenshots.ps1
```

৩. অ্যাপে স্ক্রিনে যান, Enter চাপুন স্ক্রিনশট নিতে
৪. সব স্ক্রিন শেষ হলে `q` লিখে বের হোন

### পদ্ধতি ২: Python স্ক্রিপ্ট (আরও উন্নত)

১. Command Prompt/Terminal খুলুন

```bash
cd G:\Web\allseba-online\scripts
python capture_admin_screenshots.py
```

**বৈশিষ্ট্যসমূহ:**
- `Enter` - সাধারণ স্ক্রিনশট
- `a` - বর্তমান অ্যাকটিভিটি নাম অনুযায়ী স্ক্রিনশট
- `l` - শেষ ১০টি স্ক্রিনশটের তালিকা দেখুন
- `q` - প্রস্থান

## অ্যাডমিন অ্যাপের স্ক্রিনসমূহ

এই অ্যাপে নিচের স্ক্রিনগুলো রয়েছে:

- SplashScreen
- LoginScreen  
- DashboardScreen
- UsersScreen
- OrdersScreen
- RechargesScreen
- WithdrawsScreen
- ServicesScreen
- CategoriesScreen
- LedgerScreen
- StaffScreen
- PermissionsMatrixScreen
- RoleHierarchyScreen
- ReferralsScreen
- NotificationsScreen
- LogsScreen
- SettingsScreen
- ProfileScreen

## টিপস

1. **মেনু ঘুরে ঘুরে** প্রতিটি স্ক্রিনে যান এবং স্ক্রিনশট নিন
2. **ডায়ালগ/বটমশিট** খুলেও স্ক্রিনশট নিতে পারেন
3. **ফর্ম স্ক্রিন** ভ্যালিডেশন স্টেটসহ নিতে পারেন
4. স্ক্রিনশট নেওয়ার পর ফাইলের নাম পরিবর্তন করে রাখা সুবিধাজনক হতে পারে

## সমস্যা সমাধান

### ADB ডিভাইস দেখা না গেলে
```bash
adb kill-server
adb start-server
adb devices
```

### Permission denied (PowerShell execution policy)
যদি PowerShell স্ক্রিপ্ট রান না করে:
```powershell
Set-ExecutionPolicy -Scope CurrentUser -ExecutionPolicy RemoteSigned
.\capture-admin-screenshots.ps1
```

### স্ক্রিনশট খালি/কালো আসে
- ডিভাইসের স্ক্রিন অন আছে কিনা নিশ্চিত করুন
- কিছু অ্যাপে নিরাপত্তাজনিত কারণে স্ক্রিনশট ব্লক করতে পারে (সাধারণত লগইন এর পর এমন হয় না)
- USB ডিবাগিং যথেষ্ট অনুমতি দেওয়া আছে কিনা দেখুন

## স্ক্রিনশট লোকেশন

সব স্ক্রিনশট সংরক্ষিত হবে:  
`G:\Web\allseba-online\screenshorts\admin-android\`
