# Alif Tools Admin Android — Local Setup

1. Install Android Studio (JBR 17+) and the SDK (platform-tools + platforms;android-36 + build-tools;35.0.0) — see `docs/deployment.md` for the shared-hosting side alone.
2. Copy
   `android-admin/local.properties.example` → `android-admin/local.properties`
   and fill `sdk.dir` plus `ADMIN_BASE_URL` (`http://127.0.0.1:8099/` for local dev).
3. Place the signing files OUTSIDE the repo by default; only `android-admin/keydata` literals (none) live in source. For local builds, create `android-admin/keystore.properties` from
   `android-admin/keystore.properties.example` — those files are gitignored.
4. Build: `cd android-admin` then
   ```
   $env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
   $env:Path="$env:JAVA_HOME\bin;$env:Path"
   .\gradlew.bat :app:testDebugUnitTest --no-daemon
   .\gradlew.bat :app:assembleDebug --no-daemon
   ```
5. The admin app reads `BuildConfig.BASE_URL` (never a hardcoded secret).

Biometric/secure-tokens note (2026-10): the admin app uses DataStore-backed tokens today and BiometricPrompt unlock is **deferred to a later phase** — no sensitive operation requires on-device biometric by default in this launch. EncryptedSharedPreferences migration (admin-app Tokenspace) is a candidate follow-up.