package com.aliftools.admin.data.model

import com.google.gson.annotations.SerializedName

/**
 * Backend JSON envelope: {success, message, data, errors}
 * Api::fail() puts errors in BOTH data and errors, so read errors.
 */
data class ApiResponse<T>(
    val success: Boolean,
    val message: String?,
    val data: T?,
    val errors: Map<String, List<String>>?
)

/**
 * Login response data.
 */
data class LoginResponse(
    @SerializedName("token") val token: String,
    @SerializedName("refresh") val refresh: String,
    @SerializedName("expires_at") val expiresAt: String?,
    val user: User
)

/**
 * Minimal user shape used across the app.
 */
data class User(
    val id: Int,
    val username: String,
    val phone: String,
    val email: String?,
    val role: String,
    val balance: String,
    val status: String,
    val fullName: String?
)

/**
 * Dashboard aggregates from /api/admin/dashboard.
 */
data class AdminDashboard(
    @SerializedName("userCount") val userCount: Int = 0,
    @SerializedName("categoryCount") val categoryCount: Int = 0,
    @SerializedName("serviceCount") val serviceCount: Int = 0,
    @SerializedName("txStats") val txStats: TxStats? = null,
    @SerializedName("openOrders") val openOrders: Int = 0,
    @SerializedName("recentLogs") val recentLogs: List<ActivityLog> = emptyList(),
    @SerializedName("topupStats") val topupStats: TopupStats? = null,
    @SerializedName("unresolvedCount") val unresolvedCount: Int = 0,
    @SerializedName("unresolvedAmount") val unresolvedAmount: String = "0.00"
)

data class TxStats(
    val count: Int = 0,
    val amount: String = "0.00"
)

data class TopupStats(
    val pending: Int = 0,
    val approved: Int = 0,
    val rejected: Int = 0
)

data class ActivityLog(
    val id: Int,
    val action: String,
    val description: String,
    @SerializedName("ip_address") val ipAddress: String?,
    @SerializedName("created_at") val createdAt: String?
)

/**
 * Paged list envelope used by list endpoints.
 */
data class PagedResponse<T>(
    val rows: List<T>,
    val total: Int,
    val page: Int,
    @SerializedName("perPage") val perPage: Int,
    @SerializedName("trashedCount") val trashedCount: Int? = null
)

/**
 * Orders list row.
 */
data class AdminOrder(
    val id: Int,
    val status: String,
    val service: String?,
    val username: String?,
    @SerializedName("user_id") val userId: Int,
    @SerializedName("created_at") val createdAt: String?,
    @SerializedName("claimed_by") val claimedBy: Int? = null
)

/**
 * Topup / recharge row.
 */
data class AdminTopup(
    val id: Int,
    val username: String?,
    val phone: String?,
    val amount: String,
    val status: String,
    val method: String?,
    val reference: String?,
    @SerializedName("created_at") val createdAt: String?
)

/**
 * Withdraw row.
 */
data class AdminWithdraw(
    val id: Int,
    val username: String?,
    val phone: String?,
    val amount: String,
    val status: String,
    @SerializedName("admin_id") val adminId: Int,
    @SerializedName("admin_balance") val adminBalance: String,
    @SerializedName("reviewed_by") val reviewedBy: Int? = null,
    @SerializedName("created_at") val createdAt: String?,
    @SerializedName("reviewed_at") val reviewedAt: String?
)

/**
 * Settings row.
 */
data class Setting(
    val id: Int,
    @SerializedName("setting_key") val key: String,
    @SerializedName("setting_value") val value: String,
    val type: String?,
    @SerializedName("updated_by") val updatedBy: Int?
)

/**
 * Admin notification queue row.
 */
data class AdminNotification(
    val id: Int,
    val subject: String?,
    val status: String,
    @SerializedName("created_at") val createdAt: String?,
    val stats: Map<String, Int>? = null
)

/**
 * Device registration (from /api/devices).
 */
data class Device(
    val id: Int,
    @SerializedName("device_token") val deviceToken: String,
    val platform: String?,
    @SerializedName("device_name") val deviceName: String?
)

/**
 * Deep-link payload inside an FCM data message.
 */
data class NotificationPayload(
    val type: String? = null,
    @SerializedName("entity_id") val entityId: String? = null,
    val route: String? = null
)

/**
 * Raw API error shape (from errors map or generic message).
 */
data class ApiError(
    val message: String,
    val errors: Map<String, List<String>> = emptyMap()
)
