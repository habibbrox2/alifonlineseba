# Alif Tools Admin Android — Deployment

## Secrets / signing
- Release signing uses `ADMIN_KEYSTORE_BASE64`, `ADMIN_STORE_PASSWORD`, `ADMIN_KEY_ALIAS`, `ADMIN_KEY_PASSWORD`, `ADMIN_BASE_URL`, optional `ANDROID_GOOGLE_SERVICES_JSON`.
- Never commit `android-admin/keystore.properties`, `*.jks`, `*.keystore`, `*.p12`, `*.pfx`.
- Generate keystore once with keytool, back it up outside the repo; SHA-1s go to Firebase console if phone auth is enabled.

## Release APK publisher runbook
1. Bump `versionCode`/`versionName` in `android-admin/app/build.gradle.kts` (must increase every published build).
2. Push; download the `admin-apk` artifact from the release workflow.
3. Verify the APK fingerprint with `app:twa:fingerprints`-style tooling.
4. Copy APK to `web/releases/`.
5. `php yii app:release:publish --file=web/releases/<name>.apk --channel=admin --version-code=<N> --version-name=<X> ...`
6. Verify `GET /api/app/version?channel=admin` (returns `download_url = /app/apk?channel=admin`) and `GET /app/apk?channel=admin`.
7. Roll back by republishing the previous APK.
   Token-storage cutover: future EncryptedSharedPreferences rollout is a one-time logout (see TokenStorage notes).

## Channel invariant
Never publish an admin row with `channel=twa`; never publish a TWA row with `channel=admin`.