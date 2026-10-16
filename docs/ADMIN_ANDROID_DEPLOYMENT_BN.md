# Alif Tools Admin Android — প্রোডাকশন রিলিজ রানবুক (বাংলা)

এই ডকুমেন্টটি `docs/ADMIN_ANDROID_DEPLOYMENT.md` (ইংরেজি) এর সংক্ষিপ্ত বাংলা সংস্করণ, যাতে একজন অপারেটর পুরো লঞ্চ প্রক্রিয়া বুঝে চালাতে পারেন।

## ১. GitHub Secrets সেটআপ
Repository → Settings → Secrets and variables → Actions-এ নিচের ভ্যালুগুলো যোগ করুন (কখনো কোডে নয়):

| Secret | বর্ণনা |
|---|---|
| `ADMIN_KEYSTORE_BASE64` | keystore ফাইলের base64 (signer দিয়ে তৈরি release keystore) |
| `ADMIN_STORE_PASSWORD` | keystore ট্রাস্ট পাসওয়ার্ড |
| `ADMIN_KEY_ALIAS` | key alias (যেমন `aliftools-admin`) |
| `ADMIN_KEY_PASSWORD` | key পাসওয়ার্ড |
| `ADMIN_BASE_URL` | production base URL (`https://<host>/`) — android বিল্ডের `BuildConfig.BASE_URL` |
| *(optional)* `ANDROID_GOOGLE_SERVICES_JSON` | firebase google-services.json (নেইলে default) |

Generate keystore example:
```
keytool -genkeypair -v -keystore admin-release.jks -alias aliftools-admin -keyalg RSA -keysize 2048 -validity 10000
base64 admin-release.jks > ADMIN_KEYSTORE_BASE64.txt
```

## ২. প্রথম বিল্ড (CI run)
```
git push --tags  # tag admin-v1.0.0 ইত্যাদি
```
GitHub Actions → "Admin Android release" → build successful → APK artifact download।

## ৩. Server-এ publish প্রক্রিয়া
```
# ১. artifact unzip
cp admin-release.apk web/releases/alif-tools-admin-1.1.0.apk

# ২. PHP console
php yii app:release:publish \
  --file=web/releases/alif-tools-admin-1.1.0.apk \
  --channel=admin \
  --version-code=7 \
  --version-name="1.1.0" \
  --min-version-code=2 \
  --notes="প্রথম admin build"
```

## ৪. স্মোক টেস্ট
```
curl -s http://127.0.0.1:8099/api/app/version?channel=admin
curl -sI http://127.0.0.1:8099/app/apk?channel=admin | head   # 200 + download_url
```
- Android app logs `AppVersion.versionCode / minVersionCode` দিয়ে verify।
- unknown channel `?channel=ios-typo` → 400 with `"Unknown release channel."`.

## ৫. Tag cut (launch)
```
git tag launch-2026-10-17
git push origin launch-2026-10-17
```

## ৬. Rollback
- পুরনো APK আবার publish (same version) অথবা `is_published=0` flipping on `channel='admin'` rows — TWA rows ছুঁবেন না।
- Local `android-admin/.gradle/` বা `.git`-এ keystore নয়; keystore backup বাইরে রাখুন।

## ৭. Security notes
- Biometric & Encrypted token storage deferred; DataStore plaintext token; device-loss ≡ token theft until patched.
- Release builds must use R8 + `proguard-rules.pro`.
- Read `docs/ADMIN_ANDROID_SECURITY.md` 1A. section (receipt endpoint 404 no leaks, nosniff, no-store).

---

*Last updated: 2026-10-17 — Phase 4 docs completion.*