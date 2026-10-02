package online.broxlab.aliftools.data

import android.content.Context
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.decodeFromJsonElement
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.json.Json
import okhttp3.FormBody
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

// ---- Wire model -----------------------------------------------------------
// Mirrors App\Service\Api's envelope: {success, message, data, errors}.

@Serializable
data class ApiEnvelope(
    val success: Boolean,
    val message: String = "",
    val data: kotlinx.serialization.json.JsonElement? = null,
)

@Serializable
data class LoginRequest(
    val identifier: String,
    val password: String,
    val device_label: String? = null,
)

@Serializable
data class TokenPair(
    val token: String,
    val refresh: String,
    @SerialName("expires_at") val expiresAt: String? = null,
)

@Serializable
data class LoggedInUser(
    val id: Long,
    val username: String,
    val phone: String = "",
    val balance: Double = 0.0,
    val role: String = "user",
)

/** Shape of `data` returned by POST /api/auth/login. */
@Serializable
data class LoginData(
    val token: String,
    val refresh: String,
    @SerialName("expires_at") val expiresAt: String? = null,
    val user: LoggedInUser? = null,
)

// ---- Token storage ---------------------------------------------------------

/**
 * Access + refresh tokens in DataStore. The bearer is exposed synchronously
 * for the OkHttp interceptor (DataStore on the calling thread is fine for a
 * one-line read that OkHttp already runs off the main thread).
 */
class TokenStore(context: Context) {
    private val prefs = context.getSharedPreferences("aliftools_auth", Context.MODE_PRIVATE)

    var bearer: String?
        get() = prefs.getString(KEY_BEARER, null)
        set(value) = prefs.edit().putString(KEY_BEARER, value).apply()

    var refresh: String?
        get() = prefs.getString(KEY_REFRESH, null)
        set(value) = prefs.edit().putString(KEY_REFRESH, value).apply()

    fun clear() = prefs.edit().clear().apply()

    private companion object {
        const val KEY_BEARER = "bearer"
        const val KEY_REFRESH = "refresh"
    }
}

/**
 * Logged-in state as an observable Flow so Compose can switch screens on it.
 */
class Session(private val tokens: TokenStore) {
    private val _isLoggedIn = MutableStateFlow(tokens.bearer != null)
    val isLoggedIn: Flow<Boolean> = _isLoggedIn

    val user = MutableStateFlow<LoggedInUser?>(null)

    fun onLogin(pair: TokenPair, user: LoggedInUser?) {
        tokens.bearer = pair.token
        tokens.refresh = pair.refresh
        this.user.value = user
        _isLoggedIn.value = true
    }

    fun onTokensRotated(pair: TokenPair) {
        tokens.bearer = pair.token
        tokens.refresh = pair.refresh
    }

    fun clear() {
        tokens.clear()
        user.value = null
        _isLoggedIn.value = false
    }
}

// ---- Retrofit API -----------------------------------------------------------
// Paths are the exact Phase 1/1.5 backend routes (config/common/routes.php).

interface AlifToolsApi {
    @retrofit2.http.POST("api/auth/login")
    suspend fun login(@retrofit2.http.Body body: LoginRequest): ApiEnvelope

    @retrofit2.http.POST("api/auth/refresh")
    suspend fun refresh(): ApiEnvelope

    @retrofit2.http.POST("api/auth/logout")
    suspend fun logout(): ApiEnvelope

    @retrofit2.http.GET("api/notifications")
    suspend fun notifications(
        @retrofit2.http.Query("page") page: Int = 1,
    ): ApiEnvelope

    @retrofit2.http.POST("api/notifications/read-all")
    suspend fun markAllRead(): ApiEnvelope

    @retrofit2.http.GET("api/service-requests/{id}")
    suspend fun serviceRequest(@retrofit2.http.Path("id") id: Long): ApiEnvelope

    @retrofit2.http.POST("api/devices")
    suspend fun registerDevice(@retrofit2.http.Body body: DeviceRegistration): ApiEnvelope

    @retrofit2.http.GET("api/devices")
    suspend fun devices(): ApiEnvelope

    @retrofit2.http.DELETE("api/devices/{id}")
    suspend fun deleteDevice(@retrofit2.http.Path("id") id: Long): ApiEnvelope
}

@Serializable
data class DeviceRegistration(
    val token: String,
    val platform: String = "android",
    val device_name: String? = null,
    val app_version: String? = null,
)

/**
 * Retrofit service wired against BuildConfig.API_BASE_URL, with the bearer
 * attached and a single transparent refresh-on-401 retry.
 */
class AuthenticatedApi(
    baseUrl: String,
    tokens: TokenStore,
    private val session: Session,
) {
    private val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
    }

    private val authInterceptor = okhttp3.Interceptor { chain ->
        val bearer = tokens.bearer
        val request = if (bearer != null) {
            chain.request().newBuilder().header("Authorization", "Bearer $bearer").build()
        } else {
            chain.request()
        }
        val response = chain.proceed(request)

        // One transparent refresh: an expired access token rotates the pair and
        // replays the original request exactly once.
        if (response.code == 401 && tokens.refresh != null && request.header("X-No-Retry") == null) {
            response.close()
            val refreshed = runCatching {
                val refreshCall = client.newCall(
                    okhttp3.Request.Builder()
                        .url(baseUrl.trimEnd('/') + "/api/auth/refresh")
                        .header("Authorization", "Bearer " + tokens.refresh)
                        .header("X-No-Retry", "1")
                        .post(FormBody.Builder().build())
                        .build(),
                ).execute()
                refreshCall.use { it.body?.string() }
            }.getOrNull()
            val pair = refreshed?.let {
                runCatching { json.decodeFromString<ApiEnvelope>(it).data }
                    .getOrNull()?.let { el -> runCatching { json.decodeFromJsonElement<TokenPair>(el) }.getOrNull() }
            }
            if (pair != null) {
                session.onTokensRotated(pair)
                return@Interceptor chain.proceed(
                    request.newBuilder().header("Authorization", "Bearer " + pair.token).build(),
                )
            }
            session.clear()
        }
        response
    }

    private val client: OkHttpClient = OkHttpClient.Builder()
        .addInterceptor(authInterceptor)
        .addInterceptor(HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.BASIC
        })
        .build()

    val service: AlifToolsApi = Retrofit.Builder()
        .baseUrl(baseUrl)
        .client(client)
        .addConverterFactory(json.asConverterFactory("application/json; charset=UTF-8".toMediaType()))
        .build()
        .create(AlifToolsApi::class.java)
}

/** Composition root: one of everything, built once in [AlifToolsApp]. */
class AppContainer(context: Context) {
    val tokens = TokenStore(context)
    val session = Session(tokens)
    val api: AlifToolsApi = AuthenticatedApi(
        BuildConfig.API_BASE_URL,
        tokens,
        session,
    ).service
}
