package com.aliftools.admin.data.api

import com.aliftools.admin.data.local.TokenStorage
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import okhttp3.Authenticator
import okhttp3.Request
import okhttp3.Response
import okhttp3.Route
import javax.inject.Inject

/**
 * Attaches the access token to every request and, on 401, tries one refresh
 * before handing control back to Retrofit (which will surface the error).
 */
class AuthInterceptor @Inject constructor(
    private val tokenStorage: TokenStorage,
    private val authApi: AuthApiService
) : Authenticator {

    override fun authenticate(route: Route?, response: Response): Request? {
        val access = runBlocking { tokenStorage.accessTokenFlow.first() } ?: return null
        val refresh = runBlocking { tokenStorage.refreshTokenFlow.first() } ?: return null

        // Don't retry more than once for the same request.
        if (responseCount(response) >= 2) return null

        val rotated = runBlocking {
            try {
                val res = authApi.refresh(refresh)
                if (res.isSuccessful) {
                    val body = res.body()
                    val newToken = body?.data?.token
                    val newRefresh = body?.data?.refresh
                    val role = body?.data?.user?.role ?: ""
                    if (newToken != null && newRefresh != null) {
                        tokenStorage.saveTokens(newToken, newRefresh, body.data?.expiresAt, role)
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
        val token = runBlocking { tokenStorage.accessTokenFlow.first() }
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
