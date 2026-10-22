# Add project specific ProGuard rules here.
# By default, the flags in this file are appended to flags specified
# in the Android SDK tools.

# Retrofit
-keepattributes Signature, InnerClasses, EnclosingMethod
-keepattributes RuntimeVisibleAnnotations, RuntimeVisibleParameterAnnotations
-keepattributes AnnotationDefault

-keepclassmembers,allowshrinking,allowobfuscation interface * {
    @retrofit2.http.* <methods>;
}

-dontwarn org.codehaus.mojo.animal_sniffer.IgnoreJRERequirement

-dontwarn kotlin.Unit

-dontwarn retrofit2.KotlinExtensions
-dontwarn retrofit2.KotlinExtensions$*

# Gson
-keepattributes Signature
-keepattributes *Annotation*
-dontwarn sun.misc.**
-keep class com.google.gson.** { *; }
-keep class * implements com.google.gson.TypeAdapterFactory
-keep class * implements com.google.gson.JsonSerializer
-keep class * implements com.google.gson.JsonDeserializer

# OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**

# Firebase
-keep class com.google.firebase.** { *; }
-keep class com.google.android.gms.** { *; }

# Biometric
-keep class androidx.biometric.** { *; }

# Compose
-keep class com.aliftools.admin.BuildConfig { *; }

# Alif Admin - keep all Hilt-related classes
-keep class com.aliftools.admin.di.** { *; }
-keepclassmembers class * { @dagger.hilt.android.Hilt class *; }
-keep class * {
    @dagger.hilt.android.AndroidEntryPoint <methods>;
    @dagger.hilt.android.AndroidEntryPoint <fields>;
}

# Retrofit specific keep rules
-keep interface retrofit2.http.FormUrlEncoded
-keep interface retrofit2.http.multipart.Part
-keep interface retrofit2.http.PartMap

# Keep admin API classes
-keepclassmembers class com.aliftools.admin.data.api.** { *; }
-keepclassmembers class com.aliftools.admin.data.model.** { *; }

# Keep OkHttp logging interceptor
-keepclassmembers class * extends okhttp3.Interceptor { *; }

# Keep Coroutine Suspendables
-keep class * implements kotlin.coroutines.Continuation { *; }

# Keep Enums
-keepclassmembers enum * {
    public static ** values();
    public static ** valueOf();
}

# Keep Parcelable classes
-keep @androidx.annotation.Keep class * implements android.os.Parcelable
-keep @androidx.annotation.Keep class * implements android.os.Parcelable$Creator

# Keep ViewModels
-keepclassmembers class * extends androidx.lifecycle.ViewModel {
    public void *getFactory();
    public void *onCleared();
}

# Keep Compose UI elements (annotation processors handle most of this)
-keep class * { @compose.ui.Bindable <fields>; }