package com.aliftools.admin.data.api

import com.aliftools.admin.data.local.TokenStorage
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.Authenticator
import okhttp3.Request
import okhttp3.Response
import okhttp3.Route
import javax.inject.Inject

/**
 * Attaches the access token to every request and, on 401, tries one refresh
 * before handing control back to Retrofit (which will surface the error).
 *
 * `authenticate()` runs on OkHttp's dispatcher thread, so the token reads are
 * wrapped in `runBlocking` with a short timeout — a stalled DataStore read
 * must never hang the whole HTTP client.
 */
class AuthInterceptor @Inject constructor(
    private val tokenStorage: TokenStorage,
    private val authApi: AuthApiService
) : Authenticator {

    override fun authenticate(route: Route?, response: Response): Request? {
        // Don't retry more than once for the same request.
        if (responseCount(response) >= 2) return null

        val access = runBlocking {
            withTimeoutOrNull(5000) { tokenStorage.accessTokenFlow.first() }
        } ?: return null
        val refresh = runBlocking {
            withTimeoutOrNull(5000) { tokenStorage.refreshTokenFlow.first() }
        } ?: return null

        val rotated = runBlocking {
            try {
                // The backend extracts the token from `Authorization: Bearer …`.
                val res = authApi.refresh("Bearer $refresh")
                if (res.isSuccessful) {
                    val body = res.body()
                    val newToken = body?.data?.token
                    val newRefresh = body?.data?.refresh
                    if (newToken != null && newRefresh != null) {
                        // Refresh returns no user object — keep the role that
                        // login already stored instead of overwriting it.
                        val role = body.data.user?.role
                            ?: runCatching { tokenStorage.userRoleFlow.first() }.getOrNull()
                            ?: ""
                        tokenStorage.saveTokens(newToken, newRefresh, body.data.expiresAt, role)
                        newToken
                    } else null
                } else null
            } catch (e: Exception) {
                null
            }
        }

        return rotated?.let { token ->
            response.request.newBuilder()
                .header("Authorization", "Bearer $token")
                .build()
        }
    }

    fun attach(request: Request): Request {
        val token = runBlocking {
            withTimeoutOrNull(5000) { tokenStorage.accessTokenFlow.first() }
        }
        return if (token != null) {
            request.newBuilder()
                .header("Authorization", "Bearer $token")
                .build()
        } else request
    }

    private fun responseCount(response: Response): Int {
        var result = 1
        var prior = response.priorResponse
        while (prior != null) {
            result++
            prior = prior.priorResponse
        }
        return result
    }
}
