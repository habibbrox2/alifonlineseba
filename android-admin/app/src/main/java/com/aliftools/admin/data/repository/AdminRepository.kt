package com.aliftools.admin.data.repository

import com.aliftools.admin.data.api.AdminApiService
import com.aliftools.admin.data.api.AuthApiService
import com.aliftools.admin.data.local.TokenStorage
import com.aliftools.admin.data.model.*
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class AdminRepository @Inject constructor(
    private val authApi: AuthApiService,
    private val adminApi: AdminApiService,
    private val tokenStorage: TokenStorage
) {

    // Auth

    suspend fun login(identifier: String, password: String): Result<LoginResponse> {
        return try {
            val res = authApi.login(identifier, password)
            if (res.isSuccessful) {
                val body = res.body()
                if (body?.success == true && body.data != null) {
                    val d = body.data
                    tokenStorage.saveTokens(d.token, d.refresh, d.expiresAt, d.user.role)
                    Result.success(d)
                } else {
                    Result.failure(Exception(body?.message ?: "Login failed"))
                }
            } else {
                Result.failure(Exception(res.body()?.message ?: "Login failed"))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun logout(): Result<Unit> {
        return try {
            val res = authApi.logout()
            tokenStorage.clearTokens()
            if (res.isSuccessful) {
                Result.success(Unit)
            } else {
                Result.failure(Exception(res.body()?.message ?: "Logout failed"))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    fun accessTokenFlow() = tokenStorage.accessTokenFlow
    fun userRoleFlow() = tokenStorage.userRoleFlow
    fun biometricEnabledFlow() = tokenStorage.biometricEnabledFlow
    suspend fun setBiometricEnabled(enabled: Boolean) = tokenStorage.setBiometricEnabled(enabled)
    suspend fun isBiometricEnabled(): Boolean = tokenStorage.biometricEnabledFlow.first()

    // Dashboard

    suspend fun dashboard(): Result<AdminDashboard> {
        return try {
            val res = adminApi.dashboard()
            if (res.isSuccessful) {
                val body = res.body()
                if (body?.success == true && body.data != null) {
                    Result.success(body.data)
                } else {
                    Result.failure(Exception(body?.message ?: "Dashboard failed"))
                }
            } else {
                Result.failure(Exception(res.body()?.message ?: "Dashboard failed"))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    // Users

    suspend fun users(
        page: Int = 1,
        query: String? = null,
        sort: String? = null,
        dir: String? = null,
        trashed: Int? = null
    ): Result<PagedResponse<User>> {
        return safeApiCall { adminApi.users(page, query, sort, dir, trashed) }
            .map { it.data!! }
    }

    suspend fun userAction(action: String, id: Int? = null, extra: Map<String, String> = emptyMap()): Result<Unit> {
        return safeApiCall {
            adminApi.usersAction(
                action = action,
                id = id,
                username = extra["username"],
                phone = extra["phone"],
                password = extra["password"],
                role = extra["role"]
            )
        }.map { }
    }

    // Orders

    suspend fun orders(page: Int = 1, query: String? = null, scope: String? = null): Result<PagedResponse<AdminOrder>> {
        return safeApiCall { adminApi.orders(page, query, scope) }
            .map { it.data!! }
    }

    suspend fun bulkStatus(ids: List<Int>, status: String): Result<Unit> {
        return safeApiCall { adminApi.ordersBulk(ids = ids, status = status) }
            .map { }
    }

    // Topups / Recharges

    suspend fun topups(page: Int = 1, status: String? = null, query: String? = null): Result<PagedResponse<AdminTopup>> {
        return safeApiCall { adminApi.topups(page, status, query) }
            .map { it.data!! }
    }

    suspend fun recharge(id: Int): Result<AdminTopup> {
        return safeApiCall { adminApi.recharge(id) }
            .map { it.data!! }
    }

    suspend fun rechargeAction(id: Int, action: String, reason: String? = null): Result<AdminTopup> {
        return safeApiCall { adminApi.rechargeAction(id, action, reason) }
            .map { it.data!! }
    }

    // Withdraws

    suspend fun withdraws(page: Int = 1, status: String? = null, query: String? = null): Result<PagedResponse<AdminWithdraw>> {
        return safeApiCall { adminApi.withdraws(page, status, query) }
            .map { it.data!! }
    }

    suspend fun withdrawAction(id: Int, action: String, reason: String? = null): Result<AdminWithdraw> {
        return safeApiCall { adminApi.withdrawAction(id, action, reason) }
            .map { it.data!! }
    }

    // Staff

    suspend fun staff(page: Int = 1, query: String? = null): Result<PagedResponse<User>> {
        return safeApiCall { adminApi.staff(page, query) }
            .map { it.data!! }
    }

    // Settings

    suspend fun settings(): Result<List<Setting>> {
        return safeApiCall { adminApi.settings() }
            .map { it.data!! }
    }

    suspend fun saveSettings(values: Map<String, String>): Result<Unit> {
        return safeApiCall { adminApi.saveSettings(values) }
            .map { }
    }

    // Logs

    suspend fun logs(page: Int = 1, perPage: Int = 25, query: String? = null): Result<PagedResponse<ActivityLog>> {
        return safeApiCall { adminApi.logs(page, perPage, query) }
            .map { it.data!! }
    }

    // Notifications

    suspend fun notifications(page: Int = 1, status: String? = null): Result<PagedResponse<AdminNotification>> {
        return safeApiCall { adminApi.notifications(page, status) }
            .map { it.data!! }
    }

    suspend fun retryNotification(id: Int): Result<Unit> {
        return safeApiCall { adminApi.retryNotification(id) }
            .map { }
    }

    // Devices

    suspend fun registerDevice(deviceToken: String, deviceName: String?): Result<Unit> {
        return safeApiCall {
            adminApi.registerDevice(
                mapOf(
                    "device_token" to deviceToken,
                    "device_name" to (deviceName ?: "Android Admin"),
                    "platform" to "android"
                )
            )
        }.map { }
    }

    suspend fun devices(): Result<List<Device>> {
        return safeApiCall { adminApi.devices() }
            .map { it.data!! }
    }

    // App version

    suspend fun appVersion(): Result<AppVersion> {
        return safeApiCall { adminApi.appVersion() }
            .map { it.data!! }
    }

    private suspend fun <T> safeApiCall(block: suspend () -> Response<ApiResponse<T>>): Result<T> {
        return try {
            val res = block()
            if (res.isSuccessful) {
                val body = res.body()
                if (body?.success == true && body.data != null) {
                    Result.success(body.data)
                } else {
                    Result.failure(Exception(body?.message ?: "API error"))
                }
            } else {
                val code = res.code()
                val msg = res.body()?.message ?: "HTTP $code"
                if (code == 401) {
                    runBlocking { tokenStorage.clearTokens() }
                }
                Result.failure(HttpException(code, msg))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }
}

class HttpException(val code: Int, message: String) : Exception(message)
