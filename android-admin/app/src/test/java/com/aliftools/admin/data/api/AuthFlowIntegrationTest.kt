package com.aliftools.admin.data.api

import com.aliftools.admin.data.local.TokenStorage
import com.aliftools.admin.data.model.EnvelopeAdapterFactory
import com.aliftools.admin.data.repository.AdminRepository
import com.aliftools.admin.data.repository.HttpException
import com.google.gson.GsonBuilder
import io.mockk.coEvery
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.test.runTest
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Drives the whole auth path over real HTTP: Retrofit -> OkHttp -> the
 * attach interceptor -> the 401 authenticator -> refresh -> retry, with only
 * the server (MockWebServer) and TokenStorage's storage faked. The unit tests
 * next door stub the services, so they cannot see a header that is attached
 * to the wrong request or a refresh that never reaches the wire.
 */
class AuthFlowIntegrationTest {

    private lateinit var server: MockWebServer
    private lateinit var repository: AdminRepository

    private val accessToken = MutableStateFlow<String?>(null)
    private val refreshToken = MutableStateFlow<String?>(null)
    private val role = MutableStateFlow<String?>(null)

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()

        val tokenStorage = mockk<TokenStorage>()
        every { tokenStorage.accessTokenFlow } returns accessToken
        every { tokenStorage.refreshTokenFlow } returns refreshToken
        every { tokenStorage.userRoleFlow } returns role
        every { tokenStorage.biometricEnabledFlow } returns MutableStateFlow(false)
        coEvery { tokenStorage.saveTokens(any(), any(), any(), any()) } coAnswers {
            accessToken.value = firstArg()
            refreshToken.value = secondArg()
            role.value = arg(3)
        }
        coEvery { tokenStorage.clearTokens() } coAnswers {
            accessToken.value = null
            refreshToken.value = null
            role.value = null
        }

        // Mirror NetworkModule: Gson with the tolerant envelope reader, a bare
        // auth client for login/refresh/logout, and the admin client that
        // attaches the bearer and authenticates a 401 with the same interceptor.
        val gson = GsonBuilder()
            .registerTypeAdapterFactory(EnvelopeAdapterFactory)
            .create()
        val baseUrl = server.url("/").toString()

        val authApi = Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(OkHttpClient.Builder().build())
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(AuthApiService::class.java)

        val authInterceptor = AuthInterceptor(tokenStorage, authApi)
        val adminClient = OkHttpClient.Builder()
            .addInterceptor { chain -> chain.proceed(authInterceptor.attach(chain.request())) }
            .authenticator(authInterceptor)
            .build()
        val adminApi = Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(adminClient)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(AdminApiService::class.java)

        repository = AdminRepository(authApi, adminApi, tokenStorage)
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `expired access token refreshes the pair and the retry carries the new one`() = runTest {
        server.enqueue(json(200, LOGIN_OK))
        server.enqueue(json(401, AUTH_REQUIRED))
        server.enqueue(json(200, REFRESH_OK))
        server.enqueue(json(200, SETTINGS_OK))

        val login = repository.login("admin", "secret")
        assertTrue(login.isSuccess)
        assertEquals("acc.1", accessToken.value)
        assertEquals("superadmin", role.value)

        val settings = repository.settings()
        assertTrue(settings.isSuccess)
        assertEquals("0", settings.getOrThrow()["maintenance_mode"])

        val loginRequest = takeRequest()
        val firstAttempt = takeRequest()
        val refreshRequest = takeRequest()
        val retry = takeRequest()

        assertEquals("/api/auth/login", loginRequest.path)
        assertTrue(loginRequest.body.readUtf8().contains("identifier=admin"))

        // The stale access token went out first, the refresh token — not the
        // access one — authorized the rotation, and the retried request wore
        // the new access token.
        assertEquals("/api/admin/settings", firstAttempt.path)
        assertEquals("Bearer acc.1", firstAttempt.getHeader("Authorization"))
        assertEquals("/api/auth/refresh", refreshRequest.path)
        assertEquals("Bearer ref.1", refreshRequest.getHeader("Authorization"))
        assertEquals("/api/admin/settings", retry.path)
        assertEquals("Bearer acc.2", retry.getHeader("Authorization"))

        // Rotated pair is persisted, and the role survives a refresh payload
        // that ships no user object.
        assertEquals("acc.2", accessToken.value)
        assertEquals("ref.2", refreshToken.value)
        assertEquals("superadmin", role.value)
    }

    @Test
    fun `a rejected refresh surfaces a coded 401 and clears the session`() = runTest {
        accessToken.value = "acc.1"
        refreshToken.value = "ref.1"
        role.value = "superadmin"

        server.enqueue(json(401, AUTH_REQUIRED))
        server.enqueue(json(401, AUTH_REQUIRED))

        val settings = repository.settings()

        val failure = settings.exceptionOrNull()
        assertTrue("expected a failure, got $settings", settings.isFailure)
        assertTrue("expected HttpException, got $failure", failure is HttpException)
        assertEquals(401, (failure as HttpException).code)

        val firstAttempt = takeRequest()
        val refreshRequest = takeRequest()
        assertEquals("Bearer acc.1", firstAttempt.getHeader("Authorization"))
        assertEquals("Bearer ref.1", refreshRequest.getHeader("Authorization"))

        // A dead refresh token must not leave a half-session behind.
        assertNull(accessToken.value)
        assertNull(refreshToken.value)
    }

    /**
     * Waits for the next request the server actually received.
     *
     * A single `takeRequest` — the request queue is consumed as it is read, so
     * wrapping it in `assertNotNull` and then reading again threw away the very
     * request the assertion had just confirmed and made the suite fail on
     * whichever request happened to be second.
     */
    private fun takeRequest(): RecordedRequest =
        server.takeRequest(5, TimeUnit.SECONDS)
            ?: error("no recorded request (server saw ${server.requestCount})")

    private fun json(code: Int, body: String): MockResponse =
        MockResponse().setResponseCode(code)
            .setHeader("Content-Type", "application/json; charset=utf-8")
            .setBody(body)

    companion object {
        private const val AUTH_REQUIRED =
            """{"success":false,"message":"Authentication required.","data":null,"errors":null}"""

        private const val LOGIN_OK =
            """{"success":true,"message":"লগইন সফল।","data":{"token":"acc.1","refresh":"ref.1","expires_at":"2026-12-01 00:00:00","user":{"id":1,"username":"admin","phone":"01700000000","email":null,"role":"superadmin","balance":"0.00"}},"errors":null}"""

        private const val REFRESH_OK =
            """{"success":true,"message":"Token rotated.","data":{"token":"acc.2","refresh":"ref.2","expires_at":"2026-12-02 00:00:00","user":null},"errors":null}"""

        private const val SETTINGS_OK =
            """{"success":true,"message":"","data":{"maintenance_mode":"0","support_phone":"01700000000"},"errors":null}"""
    }
}
