package com.aliftools.admin.di

import com.aliftools.admin.BuildConfig
import com.aliftools.admin.data.api.AdminApiService
import com.aliftools.admin.data.api.AuthApiService
import com.aliftools.admin.data.api.AuthInterceptor
import com.aliftools.admin.data.model.EnvelopeAdapterFactory
import com.google.gson.Gson
import com.google.gson.GsonBuilder
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit
import javax.inject.Named
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {

    @Provides
    @Singleton
    fun provideBaseUrl(): String = BuildConfig.BASE_URL

    @Provides
    @Singleton
    fun provideLoggingInterceptor(): HttpLoggingInterceptor =
        HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG) {
                HttpLoggingInterceptor.Level.BODY
            } else {
                HttpLoggingInterceptor.Level.NONE
            }
        }

    /**
     * One Gson for both clients, registering the tolerant envelope reader —
     * without it a validation failure whose `data` is a JSON array throws
     * mid-parse and the server's Bengali message never reaches the UI.
     */
    @Provides
    @Singleton
    fun provideGson(): Gson =
        GsonBuilder()
            .registerTypeAdapterFactory(EnvelopeAdapterFactory)
            .create()

    private fun OkHttpClient.Builder.base(logging: HttpLoggingInterceptor): OkHttpClient.Builder =
        addInterceptor(logging)
            .connectTimeout(30, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)

    /**
     * Auth endpoints (login/refresh/logout) get their own client on purpose.
     * Refresh must present the *refresh* token as its own Authorization header
     * and logout presents the access token explicitly, so neither wants the
     * bearer-attach interceptor — and routing them through the main client
     * would make AuthInterceptor -> AuthApiService -> OkHttpClient a Dagger
     * dependency cycle ([Dagger/DependencyCycle] at kapt time).
     */
    @Provides
    @Singleton
    @Named("auth")
    fun provideAuthOkHttp(logging: HttpLoggingInterceptor): OkHttpClient =
        OkHttpClient.Builder().base(logging).build()

    /**
     * Main admin client: attaches the current access token to every request
     * (unless the caller set Authorization itself) and, on a 401, rotates the
     * refresh token once and retries the original request. If the refresh is
     * itself rejected, authenticate() returns null and the 401 surfaces to the
     * repository, which clears the store.
     */
    @Provides
    @Singleton
    @Named("admin")
    fun provideOkHttp(
        authInterceptor: AuthInterceptor,
        logging: HttpLoggingInterceptor
    ): OkHttpClient =
        OkHttpClient.Builder().base(logging)
            .addInterceptor { chain ->
                chain.proceed(authInterceptor.attach(chain.request()))
            }
            .authenticator(authInterceptor)
            .build()

    @Provides
    @Singleton
    fun provideAuthApi(baseUrl: String, @Named("auth") client: OkHttpClient, gson: Gson): AuthApiService =
        Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(AuthApiService::class.java)

    @Provides
    @Singleton
    fun provideAdminApi(baseUrl: String, @Named("admin") client: OkHttpClient, gson: Gson): AdminApiService =
        Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(AdminApiService::class.java)
}
