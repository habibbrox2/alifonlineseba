package com.aliftools.admin.data.api

import com.aliftools.admin.data.local.TokenStorage
import com.aliftools.admin.data.model.ApiResponse
import com.aliftools.admin.data.model.LoginResponse
import io.mockk.Runs
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.every
import io.mockk.just
import io.mockk.mockk
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.runTest
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.Response
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import retrofit2.HttpException

class AuthInterceptorTest {

    private val tokenStorage = mockk<TokenStorage>()
    private val authApi = mockk<AuthApiService>()
    private val interceptor = AuthInterceptor(tokenStorage, authApi)

    @Test
    fun `attach adds the bearer when a token is stored`() {
        every { tokenStorage.accessTokenFlow } returns flowOf("sel.ver")

        val out = interceptor.attach(Request.Builder().url(URL).build())

        assertEquals("Bearer sel.ver", out.header("Authorization"))
    }

    @Test
    fun `attach leaves the request alone when logged out`() {
        every { tokenStorage.accessTokenFlow } returns flowOf(null)

        val out = interceptor.attach(Request.Builder().url(URL).build())

        assertNull(out.header("Authorization"))
    }

    @Test
    fun `authenticate presents the refresh token as a bearer and keeps the stored role`() = runTest {
        every { tokenStorage.accessTokenFlow } returns flowOf("old.access")
        every { tokenStorage.refreshTokenFlow } returns flowOf("old.refresh")
        every { tokenStorage.userRoleFlow } returns flowOf("superadmin")
        coEvery { tokenStorage.saveTokens(any(), any(), any(), any()) } just Runs

        // POST /api/auth/refresh reads the token from Authorization, not a
        // form field — a raw value here would answer 401 forever.
        coEvery { authApi.refresh("Bearer old.refresh") } returns retrofit2.Response.success(
            ApiResponse(
                success = true,
                message = "Token rotated.",
                data = LoginResponse(
                    token = "new.access",
                    refresh = "new.refresh",
                    expiresAt = "2026-12-01 00:00:00",
                    user = null, // refresh never ships a user object
                ),
                errors = null,
            ),
        )

        val retried = interceptor.authenticate(null, unauthorized())

        assertEquals("Bearer new.access", retried?.header("Authorization"))
        // The role login stored must survive a refresh whose payload omits it.
        coVerify { tokenStorage.saveTokens("new.access", "new.refresh", "2026-12-01 00:00:00", "superadmin") }
    }

    @Test
    fun `authenticate gives up when the refresh is rejected`() = runTest {
        every { tokenStorage.accessTokenFlow } returns flowOf("old.access")
        every { tokenStorage.refreshTokenFlow } returns flowOf("old.refresh")
        coEvery { authApi.refresh(any()) } returns retrofit2.Response.success(
            ApiResponse(success = false, message = "Invalid or expired refresh token.", data = null, errors = null),
        )

        val retried = interceptor.authenticate(null, unauthorized())

        assertNull(retried)
    }

    private fun unauthorized(): Response =
        Response.Builder()
            .request(Request.Builder().url(URL).build())
            .protocol(Protocol.HTTP_1_1)
            .code(401)
            .message("Unauthorized")
            .build()

    companion object {
        private const val URL = "http://localhost/api/admin/users"
    }
}
