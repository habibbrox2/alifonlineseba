# Alif Tools — Android app (Trusted Web Activity)

A **Trusted Web Activity**: the real `https://allseba.dgtts.org`, rendered full
screen by Chrome, wrapped in an APK the user installs from `/app/apk`. It is a *thin*
app — there is no bundled site, no offline copy, no duplicated API client. Whatever
`main` deploys is what the app shows, with no store review in between.

> **Status: unbuilt.** Nothing in this tree has been through Gradle: this box has no
> JDK and no Android SDK. `.github/workflows/android-apk.yml` is the first real
> validation, and a green run is the thing to look for. Everything else — the PHP
> side of the trust handshake — is covered by tests and is known good.

This is a sibling of [`../android`](../android), not a replacement for it. That tree is
the **native** client (Kotlin, Jetpack Compose, FCM, JSON API only). This tree is the
**web** client. They ship as two different APKs under two different application ids and
coexist on the same device.

## Why not a WebView

A WebView app embeds a browser engine in your APK and points it at your URL. For this
site that would be a regression, not a wrapper:

- **Web push dies.** The site's Service Worker can only post notifications if a real
  browser engine backs it, with the origin's Digital Asset Links association in place.
  Inside a WebView the subscription either never registers or silently stops firing,
  which quietly breaks the whole notification system in
  [`../docs/webpush-runbook.md`](../docs/webpush-runbook.md).
- **No address bar means no credibility and no way out.** A TWA that fails to verify
  degrades to a browser Custom Tab that *has* a bar, so a broken association is visible
  instead of a convincing impostor window.
- **You ship Chrome for nothing.** A TWA costs about 20 KB of app code; a WebView APK
  carries an engine the OS already has, and inherits its own CVE surface.
- **It breaks on every WebView update.** A TWA tracks the installed Chrome.

The one thing a WebView genuinely buys — an origin that cannot be verified by anyone
else — a TWA has too, and enforces it through the certificate, not through the app.

## Project layout

```
twa/
├── twa-manifest.json               app identity in Bubblewrap's shape (see below)
├── gradle/libs.versions.toml       pinned AGP / android-browser-helper / androidx
├── .github/workflows/android-apk.yml   (repo root) the only path that can build this
└── app/
    ├── build.gradle.kts            namespace, signing, Java 17, the two dependencies
    └── src/main/
        ├── AndroidManifest.xml     every android-browser-helper meta-data value
        ├── java/…/LauncherActivity.java   the TWA, portrait-locked on Android 8+
        ├── java/…/DelegationService.java   the service that receives web push
        └── res/
            ├── values/{strings,colors,config}.xml   host, URL, brand, notification switch
            ├── drawable/ic_notification_icon.xml    the status-bar silhouette
            ├── mipmap-*/ic_launcher.png             copied from ../android
            └── xml/filepaths.xml                    FileProvider paths for the splash
```

`twa-manifest.json` is **documentation, not build input.** Nothing reads it. It records
the app's identity in the shape `npx @bubblewrap/cli` expects, so the two can be
compared if you ever run the real generator. This tree is hand-written instead, because
the CLI would overwrite the comments that explain the non-obvious values (the
`androix.browser.trusted.*` typo, the missing `usesCleartextTraffic`, the
`applicationId` that must differ from the native client's).

## Prerequisites

| | |
|---|---|
| JDK | **17**, exactly. AGP 8.13.x rejects 11 and produces a confusing "Unsupported class file major version" on 21+. |
| Android SDK | platform **36**, build-tools **35.0.0**, accepted licences. |
| Android Studio | Optional. Koala or newer; open `twa/` directly and it is a normal Gradle project. |

Nothing Android-toolchain belongs on the PHP host, so there is deliberately no
Android build in the site's deploy scripts. Three ways to build:

```bash
# 1. CI — the only option that needs nothing installed here.
#    Push to main (or PR) touching twa/, or run the "Android APK (TWA)" workflow
#    by hand. Artifacts land as `twa-apk`.

# 2. Android Studio — File > Open, pick twa/.

# 3. Command line
cd twa
./gradlew assembleDebug        # signed with the stock debug key
./gradlew assembleRelease      # signed only if a keystore is configured, else unsigned
```

`assembleRelease` **always** succeeds as a smoke test: with no keystore configured it
produces `app-release-unsigned.apk`, which installs via `adb` but can never be
assetlinks-verified. Output lands in `app/build/outputs/apk/`.

## Signing

Create a keystore once and keep it forever. Losing it means the installed app can never
be updated in place, and the next build publishes a second, conflicting certificate.

```bash
keytool -genkeypair -v \
  -keystore twa-release.jks \
  -alias twa \
  -keyalg RSA -keysize 4096 -validity 10000 \
  -storetype PKCS12
```

Then point the build at it, either with a gitignored `twa/keystore.properties` (see
[`twa/.gitignore`](.gitignore), which already excludes it):

```properties
storeFile=twa-release.jks
storePassword=…
keyAlias=twa
keyPassword=…
```

or with environment variables, which is what CI uses:

```bash
export TWA_STORE_FILE=/abs/path/twa-release.jks   # relative paths resolve inside app/
export TWA_STORE_PASSWORD=… TWA_KEY_ALIAS=twa TWA_KEY_PASSWORD=…
```

For CI, add repository secrets: `TWA_KEYSTORE_BASE64` (the base64 of the `.jks` — a
binary secret corrupts on newlines), `TWA_STORE_PASSWORD`, `TWA_KEY_ALIAS`,
`TWA_KEY_PASSWORD`.

## The trust handshake

A TWA is only allowed to hide the address bar for an origin that names its signing
certificate in `/.well-known/assetlinks.json`. Three values have to agree, and the
failure mode when they don't is a browser bar rather than an error message:

| # | Value | Where it lives |
|---|---|---|
| 1 | `applicationId` = `online.broxlab.aliftools.twa` | [`app/build.gradle.kts`](app/build.gradle.kts) |
| 2 | `TWA_FINGERPRINTS` = that id + the SHA-256 of the signing cert | `.env` |
| 3 | `TWA_ORIGIN` = `https://allseba.dgtts.org` | `.env` |

Work through it in this order, because each step depends on the one before:

```bash
# 1. Get the certificate the build actually produced.
keytool -exportcert -alias twa -keystore twa-release.jks -file release.cer

# 2. Turn it into the exact .env line. This command reads the certificate itself —
#    no JDK needed, so it runs fine on the XAMPP box.
php yii app:twa:fingerprints --cert=release.cer --package=online.broxlab.aliftools.twa
```

It prints the two lines to paste:

```dotenv
TWA_ORIGIN=https://allseba.dgtts.org
TWA_FINGERPRINTS=online.broxlab.aliftools.twa@SHA256:AA:BB:CC:…
```

List **both** certificates if you install debug and release builds. They are signed by
different keys, and a build whose certificate is not listed will always show the
browser bar:

```dotenv
TWA_FINGERPRINTS=online.broxlab.aliftools.twa@SHA256:<debug>,\
online.broxlab.aliftools.twa@SHA256:<release>
```

Then check the origin actually publishes it:

```bash
php yii app:twa:fingerprints          # prints the document the site will serve
curl -i https://allseba.dgtts.org/.well-known/assetlinks.json
```

`200` with a statement naming `online.broxlab.aliftools.twa` is the pass condition. A
`404` with a `detail`/`fix` JSON body means the `.env` values did not reach PHP; an
empty `[]` is never served on purpose, because that reads as "this site belongs to no
app". Google has an online checker for the finished article:

```
https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=https://allseba.dgtts.org&relation=delegate_permission/common.handle_all_urls
```

`TWA_ORIGIN` is configuration, never the request's `Host` header — see
[`../src/Service/TwaAssetLinks.php`](../src/Service/TwaAssetLinks.php). Reinstalling the
app is required after any fingerprint change; Android caches the decision.

## Publishing

The APK is a file on the `app_release` table, served by the same pipeline the native
client uses — nothing app-specific:

1. Copy the built APK into `web/releases/` (outside the document root).
2. Insert a row in `app_release` with `apk_path` **relative to `web/releases/`**,
   `version_code` above the current one, and publish it.
3. `/app` shows the button, `/app/apk` streams the file, and
   `AppReleaseService::versionPayload()` answers the in-app update check.

See [`../src/Service/AppReleaseService.php`](../src/Service/AppReleaseService.php) and
[`../migrations/M240114000000_CreatePushSubscriptionAndAppRelease.php`](../migrations/M240114000000_CreatePushSubscriptionAndAppRelease.php).
Gate the whole thing with `APP_DOWNLOADS_ENABLED=0` while testing.

## When it does not verify

| Symptom | Cause |
|---|---|
| Opens with a visible browser bar / address bar | The association failed. Check `assetlinks.json` returns 200, then that the package in it is `online.broxlab.aliftools.twa` — **not** `online.broxlab.aliftools`, which belongs to the native client and can never be satisfied by this APK. |
| Worked from a debug build, not a release one | Debug and release are different certificates. List both, or install the build you published. |
| Worked, then broke after a rebuild | A new keystore was used, so the published fingerprint no longer matches. Re-run step 2 above and reinstall. |
| No notifications at all | `enableNotification` is false, or the app is not verified (delegated push needs the association), or the site has no subscription for this visitor. Check `php yii app:webpush:check` and the runbook. |
| Notifications show the app icon's *colour*, not white | `SMALL_ICON` is not being applied. It must be a flat white silhouette; a coloured vector is re-tinted by the system. |
| `assetlinks.json` is 404 on the live site | `TWA_ORIGIN`/`TWA_FINGERPRINTS` are unset, or the path is behind a rewrite that drops `/.well-known/`. The 404 body names which one is missing. |
| Served over http in the fallback | Should be impossible: there is no `usesCleartextTraffic` in this manifest, deliberately. If you add one, you have re-broken the fallback path's security. |

Full operator runbook: [`../docs/webpush-runbook.md`](../docs/webpush-runbook.md).
