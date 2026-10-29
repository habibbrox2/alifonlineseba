package com.aliftools.admin.data.repository

import com.aliftools.admin.data.api.ActionResponse
import com.aliftools.admin.data.api.AdminApiService
import com.aliftools.admin.data.api.AppVersion
import com.aliftools.admin.data.api.AuthApiService
import com.aliftools.admin.data.api.WithdrawEnvelope
import com.aliftools.admin.data.local.TokenStorage
import com.aliftools.admin.data.model.*
import com.aliftools.admin.BuildConfig
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import retrofit2.Response
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
                val d = body?.data
                val user = d?.user
                if (body?.success == true && d != null && user != null) {
                    tokenStorage.saveTokens(d.token, d.refresh, d.expiresAt, user.role)
                    Result.success(d)
                } else {
                    Result.failure(Exception(body?.message ?: "Login failed"))
                }
            } else {
                val errorStr = res.errorBody()?.string()
                val serverMsg = parseErrorMessage(errorStr)
                Result.failure(Exception(serverMsg ?: "Login failed"))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    /**
     * Signs in with a Firebase ID token instead of a password.
     *
     * Shares the success path with {@see login} so both methods end with the
     * same token pair stored and the same activity-log trail; only the way the
     * user proved who they are differs.
     */
    suspend fun loginWithFirebase(idToken: String, provider: String = "phone"): Result<LoginResponse> {
        return try {
            val res = authApi.firebaseLogin(idToken, provider)
            if (res.isSuccessful) {
                val body = res.body()
                val d = body?.data
                val user = d?.user
                if (body?.success == true && d != null && user != null) {
                    tokenStorage.saveTokens(d.token, d.refresh, d.expiresAt, user.role)
                    Result.success(d)
                } else {
                    Result.failure(Exception(body?.message ?: "লগইন ফেইল হয়েছে।"))
                }
            } else {
                val errorStr = res.errorBody()?.string()
                val serverMsg = parseErrorMessage(errorStr)
                Result.failure(Exception(serverMsg ?: "লগইন ফেইল হয়েছে।"))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun logout(): Result<Unit> {
        return try {
            val access = tokenStorage.accessTokenFlow.first()
            val refresh = tokenStorage.refreshTokenFlow.first()
            // The access token and its refresh token are two separate rows,
            // each valid for its own TTL, so both have to be revoked or one
            // credential survives the logout. A failed revoke (offline) must
            // not trap the user in a half-logged-out state: local state is
            // cleared either way.
            refresh?.let { runCatching { authApi.logout("Bearer $it") } }
            access?.let { runCatching { authApi.logout("Bearer $it") } }
            tokenStorage.clearTokens()
            Result.success(Unit)
        } catch (e: Exception) {
            tokenStorage.clearTokens()
            Result.failure(e)
        }
    }

    fun accessTokenFlow() = tokenStorage.accessTokenFlow
    fun userRoleFlow() = tokenStorage.userRoleFlow
    fun biometricEnabledFlow() = tokenStorage.biometricEnabledFlow
    suspend fun setBiometricEnabled(enabled: Boolean) = tokenStorage.setBiometricEnabled(enabled)
    suspend fun isBiometricEnabled(): Boolean = tokenStorage.biometricEnabledFlow.first()

    // Remember me — identifier only, never the password.

    fun rememberedIdentifierFlow() = tokenStorage.rememberedIdentifierFlow
    suspend fun rememberedIdentifier(): String? = tokenStorage.rememberedIdentifierFlow.first()
    suspend fun setRememberedIdentifier(identifier: String?) =
        tokenStorage.setRememberedIdentifier(identifier)

    // Dashboard

    // Goes through safeApiCall so a 401 behaves like every other admin
    // endpoint: the session is cleared and the caller gets a coded
    // HttpException instead of an opaque "Dashboard failed".
    suspend fun dashboard(): Result<AdminDashboard> = safeApiCall { adminApi.dashboard() }

    // Users

    suspend fun users(
        page: Int = 1,
        query: String? = null,
        sort: String? = null,
        dir: String? = null,
        trashed: String? = null
    ): Result<PagedResponse<User>> {
        return safeApiCall { adminApi.users(page, query, sort, dir, trashed) }
    }

    suspend fun userAction(action: String, id: Int? = null, extra: Map<String, String> = emptyMap()): Result<ActionResponse> {
        return safeApiCall {
            adminApi.usersAction(
                action = action,
                id = id,
                username = extra["username"],
                phone = extra["phone"],
                password = extra["password"],
                role = extra["role"],
                email = extra["email"]
            )
        }
    }

    // Orders

    suspend fun orders(page: Int = 1, query: String? = null, scope: String? = null): Result<PagedResponse<AdminOrder>> {
        return safeApiCall { adminApi.orders(page, query, scope) }
    }

    suspend fun bulkStatus(ids: List<Int>, status: String): Result<ActionResponse> {
        return safeApiCall { adminApi.ordersBulk(ids = ids, status = status) }
    }

    suspend fun order(id: Int): Result<OrderDetailEnvelope> {
        return safeApiCall { adminApi.order(id) }
    }

    suspend fun orderAction(
        id: Int,
        action: String,
        status: String? = null,
        reason: String? = null,
        adminNote: String? = null
    ): Result<ActionResponse> {
        return safeApiCall { adminApi.orderAction(id, action, status, reason, adminNote) }
    }

    suspend fun profile(): Result<AdminProfile> {
        return safeApiCall { adminApi.profile() }
    }

    suspend fun updateProfile(fullName: String?, email: String?, phone: String?): Result<AdminProfile> {
        return safeApiCall { adminApi.updateProfile(fullName = fullName, email = email, phone = phone) }
    }

    suspend fun changePassword(currentPass: String, newPass: String, confirmPass: String): Result<AdminProfile> {
        return safeApiCall {
            adminApi.updateProfile(
                action = "password",
                currentPass = currentPass,
                newPass = newPass,
                confirmPass = confirmPass
            )
        }
    }

    // Topups / Recharges

    suspend fun topups(page: Int = 1, status: String? = null, query: String? = null): Result<PagedResponse<AdminTopup>> {
        return safeApiCall { adminApi.topups(page, status, query) }
    }

    suspend fun recharge(id: Int): Result<AdminTopup> {
        return safeApiCall { adminApi.recharge(id) }
            .map { it.topup }
    }

    suspend fun rechargeAction(id: Int, action: String, reason: String? = null): Result<ActionResponse> {
        return safeApiCall { adminApi.rechargeAction(id, action, reason) }
    }

    // Withdraws

    suspend fun withdraws(page: Int = 1, status: String? = null, query: String? = null): Result<PagedResponse<AdminWithdraw>> {
        return safeApiCall { adminApi.withdraws(page, status, query) }
    }

    suspend fun withdrawAction(id: Int, action: String, reason: String? = null): Result<WithdrawEnvelope> {
        return safeApiCall { adminApi.withdrawAction(id, action, reason) }
    }

    // Staff

    suspend fun staff(page: Int = 1, query: String? = null): Result<PagedResponse<User>> {
        return safeApiCall { adminApi.staff(page, query) }
    }

    suspend fun staffAction(action: String, id: Int): Result<ActionResponse> {
        return safeApiCall { adminApi.staffAction(action = action, id = id) }
    }

    // Settings — GET and POST both return the flat {key: value} map that
    // SettingsRepository::all() builds, not a list of rows.
    suspend fun settings(): Result<Map<String, String>> {
        return safeApiCall { adminApi.settings() }
    }

    suspend fun saveSettings(values: Map<String, String>): Result<Unit> {
        return safeApiCall { adminApi.saveSettings(values) }
            .map { }
    }

    // Logs
    suspend fun logs(page: Int = 1, perPage: Int = 25, query: String? = null): Result<PagedResponse<ActivityLog>> {
        return safeApiCall { adminApi.logs(page, perPage, query) }
    }

    // Notifications

    suspend fun notifications(page: Int = 1, status: String? = null): Result<PagedResponse<AdminNotification>> {
        return safeApiCall { adminApi.notifications(page, status) }
    }

    suspend fun retryNotification(id: Int): Result<ActionResponse> {
        return safeApiCall { adminApi.retryNotification(id) }
    }

    suspend fun deleteNotification(id: Int): Result<ActionResponse> {
        return safeApiCall { adminApi.deleteNotification(id) }
    }

    // Devices

    suspend fun registerDevice(deviceToken: String, deviceName: String?): Result<Unit> {
        return safeApiCall {
            adminApi.registerDevice(
                mapOf(
                    // DeviceApiAction reads `token` out of the JSON body.
                    "token" to deviceToken,
                    "device_name" to (deviceName ?: "Android Admin"),
                    "platform" to "android",
                    "app_version" to BuildConfig.VERSION_NAME
                )
            )
        }.map { }
    }

    suspend fun devices(): Result<List<Device>> {
        return safeApiCall { adminApi.devices() }
            .map { it.devices }
    }

    // App version

    suspend fun appVersion(): Result<AppVersion> {
        return safeApiCall { adminApi.appVersion() }
    }

    // Categories
    suspend fun categories(): Result<Map<String, Any?>> {
        return safeApiCall { adminApi.categories() }
    }

    suspend fun categoriesAction(
        action: String,
        id: Int? = null,
        name: String? = null,
        slug: String? = null,
        icon: String? = null,
        description: String? = null,
        accent: String? = null,
        sortOrder: Int? = null,
        status: String? = null,
    ): Result<ActionResponse> {
        return safeApiCall {
            adminApi.categoriesAction(action, id, name, slug, icon, description, accent, sortOrder, status)
        }
    }

    // Services
    suspend fun services(trashed: String? = null): Result<Map<String, Any?>> {
        return safeApiCall { adminApi.services(trashed) }
    }

    suspend fun servicesAction(
        action: String,
        id: Int? = null,
        categoryId: Int? = null,
        name: String? = null,
        slug: String? = null,
        icon: String? = null,
        description: String? = null,
        price: String? = null,
        unit: String? = null,
        sortOrder: Int? = null,
        status: String? = null,
    ): Result<ActionResponse> {
        return safeApiCall {
            adminApi.servicesAction(action, id, categoryId, name, slug, icon, description, price, unit, sortOrder, status)
        }
    }

    // Ledger
    suspend fun ledger(page: Int = 1, scope: String? = null, query: String? = null): Result<Map<String, Any?>> {
        return safeApiCall { adminApi.ledger(page, scope, query) }
    }

    // Referrals
    suspend fun referrals(page: Int = 1): Result<Map<String, Any?>> {
        return safeApiCall { adminApi.referrals(page) }
    }

    // Role hierarchy
    suspend fun roleHierarchy(): Result<Map<String, Any?>> {
        return safeApiCall { adminApi.roleHierarchy() }
    }

    // Permissions matrix
    suspend fun permissionsMatrix(): Result<Map<String, Any?>> {
        return safeApiCall { adminApi.permissionsMatrix() }
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
                val errorStr = res.errorBody()?.string()
                val serverMsg = parseErrorMessage(errorStr)
                val msg = serverMsg ?: "HTTP $code"
                if (code == 401) {
                    runBlocking { tokenStorage.clearTokens() }
                }
                Result.failure(HttpException(code, msg))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    private fun parseErrorMessage(errorBody: String?): String? {
        if (errorBody.isNullOrBlank()) return null
        return try {
            val json = JSONObject(errorBody)
            json.optString("message").takeIf { it.isNotBlank() }
        } catch (_: Exception) {
            null
        }
    }
}

class HttpException(val code: Int, message: String) : Exception(message)
