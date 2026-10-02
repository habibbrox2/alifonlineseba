plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.kotlin.compose)
    // google-services is NOT applied: there is deliberately no google-services.json
    // in the repo (it binds the app to one Firebase project). Add yours and
    // uncomment to enable FCM in a real build.
    // alias(libs.plugins.google.services)
}

android {
    namespace = "online.broxlab.aliftools"
    compileSdk = 34

    defaultConfig {
        applicationId = "online.broxlab.aliftools"
        minSdk = 24
        targetSdk = 34
        versionCode = 1
        versionName = "0.1.0"
    }

    buildTypes {
        debug {
            // The PHP dev server on this machine — reachable from the emulator
            // as 10.0.2.2. Override in local.properties, never commit a real host.
            buildConfigField("String", "API_BASE_URL", "\"http://10.0.2.2:8099/\"")
        }
        release {
            buildConfigField("String", "API_BASE_URL", "\"https://onlinesheba.broxlab.online/\"")
            isMinifyEnabled = false
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    // Backports the API 31 splash screen down to minSdk 24, so one theme
    // definition gives the branded launch screen on every supported device.
    implementation(libs.androidx.core.splashscreen)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.ui.tooling.preview)
    debugImplementation(libs.androidx.compose.ui.tooling)

    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.datastore.preferences)
    implementation(libs.androidx.work.runtime.ktx)

    implementation(libs.retrofit)
    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.retrofit.kotlinx.serialization)

    // FCM needs a google-services.json + the plugin above; the dependency is
    // harmless without them and the service class is written for it.
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
}
