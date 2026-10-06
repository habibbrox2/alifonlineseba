import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
}

// Release signing comes from a gitignored `keystore.properties` next to this
// build, or from environment variables so CI never needs that file:
//
//   TWA_STORE_FILE      path to the .jks (relative paths resolve against this module)
//   TWA_STORE_PASSWORD  keystore password
//   TWA_KEY_ALIAS       signing key alias
//   TWA_KEY_PASSWORD    key password
//
// When none of that is present the release build simply produces an unsigned
// APK, so `assembleRelease` still works for a smoke test on a machine that has
// no keystore yet. `assembleDebug` is signed with the stock debug key and needs
// no configuration at all.
val keystoreProperties = Properties().apply {
    val file = rootProject.file("keystore.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}

fun secret(vararg keys: String): String? =
    keys.asSequence()
        .map { keystoreProperties.getProperty(it) ?: System.getenv(it) }
        .firstOrNull { !it.isNullOrBlank() }

val releaseStoreFile = secret("TWA_STORE_FILE", "storeFile")

android {
    namespace = "online.broxlab.aliftools.twa"
    compileSdk = 36

    defaultConfig {
        // Deliberately distinct from the native client's applicationId: a TWA
        // and the native app are different apps, and two APKs cannot share an
        // id on one device. Whatever is set here must match the package_name
        // published in /.well-known/assetlinks.json (TWA_ORIGIN /
        // TWA_FINGERPRINTS in .env).
        applicationId = "online.broxlab.aliftools.twa"
        // 24 matches ../android. android-browser-helper's own floor is 23.
        minSdk = 24
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"
    }

    signingConfigs {
        if (releaseStoreFile != null) {
            create("release") {
                storeFile = file(releaseStoreFile)
                storePassword = secret("TWA_STORE_PASSWORD", "storePassword")
                keyAlias = secret("TWA_KEY_ALIAS", "keyAlias")
                keyPassword = secret("TWA_KEY_PASSWORD", "keyPassword")
            }
        }
    }

    buildTypes {
        release {
            // The whole app is two empty subclasses; R8 would strip nothing but
            // the manifest-referenced components it is already told to keep.
            isMinifyEnabled = false
            signingConfigs.findByName("release")?.let { signingConfig = it }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    lint {
        // Same call Bubblewrap's template makes: lint is useful while editing
        // but must not gate `assembleRelease` on a machine or runner whose SDK
        // happens to ship newer checks.
        checkReleaseBuilds = false
    }
}

dependencies {
    // LauncherActivity extends com.google.androidbrowserhelper.trusted.LauncherActivity;
    // the TrustedWebActivityService in this manifest extends its DelegationService.
    // Neither exists without this dependency.
    implementation(libs.browser.helper)

    // FileProvider, used to hand shared files to the browser.
    implementation(libs.androidx.core)
}
