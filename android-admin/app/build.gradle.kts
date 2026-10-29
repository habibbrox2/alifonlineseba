import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.hilt.android)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.kotlin.kapt)
    alias(libs.plugins.compose.compiler)
}

val keystoreProperties = Properties().apply {
    val file = rootProject.file("keystore.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}

// local.properties is the gitignored per-machine config documented by
// local.properties.example; keystore.properties and env vars stay the
// sources for release signing and CI.
val localProperties = Properties().apply {
    val file = rootProject.file("local.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}

fun secret(vararg keys: String): String? =
    keys.asSequence()
        .map { keystoreProperties.getProperty(it) ?: System.getenv(it) }
        .firstOrNull { !it.isNullOrBlank() }

fun localProperty(key: String): String? =
    localProperties.getProperty(key)?.takeIf { it.isNotBlank() }

val releaseStoreFile = secret("ADMIN_STORE_FILE", "storeFile")
val baseUrl = secret("ADMIN_BASE_URL")
    ?: localProperty("ADMIN_BASE_URL")
    ?: "https://allseba.online/"

val rawVersionName = System.getenv("APP_VERSION_NAME")
    ?: System.getenv("GITHUB_REF_NAME")?.removePrefix("android-admin-v")?.removePrefix("v")
    ?: (project.findProperty("versionName") as? String)
    ?: "1.0.0"

val appVersionName = if (rawVersionName.matches(Regex("^[0-9]+(\\.[0-9]+)+.*$"))) rawVersionName else "1.0.0"

val appVersionCode = System.getenv("APP_VERSION_CODE")?.toIntOrNull()
    ?: (project.findProperty("versionCode") as? String)?.toIntOrNull()
    ?: run {
        val digits = appVersionName.split(".").mapNotNull { part ->
            part.takeWhile { it.isDigit() }.toIntOrNull()
        }
        if (digits.isNotEmpty()) {
            val major = digits.getOrElse(0) { 1 }
            val minor = digits.getOrElse(1) { 0 }
            val patch = digits.getOrElse(2) { 0 }
            major * 10000 + minor * 100 + patch
        } else {
            1
        }
    }

android {
    namespace = "com.aliftools.admin"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.aliftools.admin"
        minSdk = 26
        targetSdk = 36
        versionCode = appVersionCode
        versionName = appVersionName

        buildConfigField("String", "BASE_URL", "\"$baseUrl\"")

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables {
            useSupportLibrary = true
        }
    }

    signingConfigs {
        if (releaseStoreFile != null) {
            create("release") {
                val candidate = file(releaseStoreFile)
                storeFile = if (candidate.exists()) candidate else rootProject.file(releaseStoreFile)
                storePassword = secret("ADMIN_STORE_PASSWORD", "storePassword")
                keyAlias = secret("ADMIN_KEY_ALIAS", "keyAlias")
                keyPassword = secret("ADMIN_KEY_PASSWORD", "keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            signingConfigs.findByName("release")?.let { signingConfig = it }
        }
        debug {
            isMinifyEnabled = false
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
        freeCompilerArgs += listOf(
            "-opt-in=androidx.compose.material3.ExperimentalMaterial3Api",
            "-opt-in=androidx.compose.foundation.ExperimentalFoundationApi"
        )
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    composeOptions {
        kotlinCompilerExtensionVersion = libs.versions.kotlin.get()
    }

    packaging {
        resources {
            // Multiple JUnit 5 jars carry the same META-INF entries; they are
            // only metadata and must not fail the merge of either APK.
            excludes += "/META-INF/{AL2.0,LGPL2.1,META-INF/LICENSE.md}"
            excludes += "/META-INF/LICENSE.md"
            excludes += "/META-INF/LICENSE-notice.md"
            excludes += "/META-INF/NOTICE.md"
        }
    }

    testOptions {
        unitTests {
            isIncludeAndroidResources = true
        }
    }
}

dependencies {
    // Core
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.core.splashscreen)

    // Compose BOM
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material3.window.size)
    implementation(libs.androidx.compose.material.icons.extended)
    implementation(libs.androidx.navigation.compose)

    // Image loading
    implementation(libs.coil.compose)

    // Networking
    implementation(libs.retrofit)
    implementation(libs.retrofit.gson)
    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)
    implementation(libs.kotlinx.serialization.json)

    // Coroutines
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.coroutines.play.services)

    // Storage
    implementation(libs.androidx.datastore.preferences)
    implementation(libs.androidx.security.crypto)

    // Biometric
    implementation(libs.androidx.biometric)

    // Firebase
    implementation(libs.firebase.messaging)
    // Phone/ID-token sign-in. Pinned like messaging (see libs.versions.toml):
    // no firebase-bom here, so each artifact carries its own version.
    implementation("com.google.firebase:firebase-auth:23.2.0")

    // Google Play Services
    implementation(libs.play.services.auth)

    // DI
    implementation(libs.hilt.android)
    kapt(libs.hilt.compiler)
    implementation(libs.hilt.navigation.compose)

    // Testing
    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)
    testImplementation(libs.turbine)
    testImplementation(libs.mockk)
    testImplementation(libs.mockwebserver)

    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.compose.ui.test)
    androidTestImplementation(libs.kotlinx.coroutines.test)
    androidTestImplementation(libs.turbine)
    androidTestImplementation(libs.mockk.android)

    debugImplementation(libs.androidx.compose.ui.tooling)
    debugImplementation(libs.androidx.compose.ui.test.manifest)
}

kapt {
    correctErrorTypes = true
}
