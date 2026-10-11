// The root script's plugin classpath (AGP -> jetifier-processor) drags in
// javapoet 1.10.0, which becomes the *parent* classloader for every
// subproject script. Hilt's hiltAggregateDeps worker needs the 1.13 API
// (ClassName.canonicalName()) and parent-first loading would otherwise
// hand it 1.10.0 -> NoSuchMethodError. Pin it here so both layers agree.
buildscript {
    configurations.named("classpath") {
        resolutionStrategy.force("com.squareup:javapoet:1.13.0")
    }
    dependencies {
        classpath("com.squareup:javapoet:1.13.0")
    }
}

plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.android) apply false
    alias(libs.plugins.kotlin.kapt) apply false
}
