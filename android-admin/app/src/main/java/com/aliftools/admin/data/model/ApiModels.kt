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
    // Null on refresh: POST /api/auth/refresh returns only the token pair.
    val user: User? = null
)

/**
 * Minimal user shape used across the app.
 *
 * `status` is nullable because the login payload ships a five-field user
 * (id, username, phone, balance, role) with no status on it.
 */
data class User(
    val id: Int = 0,
    val username: String = "",
    val phone: String? = null,
    val email: String? = null,
    val role: String = "user",
    val balance: String = "0.00",
    val status: String? = null,
    @SerializedName("full_name") val fullName: String? = null,
    @SerializedName("deleted_at") val deletedAt: String? = null
)

/**
 * Dashboard aggregates from /api/admin/dashboard.
 */
data class AdminDashboard(
    @SerializedName("userCount") val userCount: Int = 0,
    @SerializedName("categoryCount") val categoryCount: Int = 0,
    @SerializedName("serviceCount") val serviceCount: Int = 0,
    @SerializedName("txStats") val txStats: TxStats? = null,
    @SerializedName("openOrders") val openOrders: OpenOrders = OpenOrders(),
    @SerializedName("recentLogs") val recentLogs: List<ActivityLog> = emptyList(),
    @SerializedName("topupStats") val topupStats: TopupStats? = null,
    @SerializedName("unresolvedCount") val unresolvedCount: Int = 0,
    @SerializedName("unresolvedAmount") val unresolvedAmount: String = "0.00",
    @SerializedName("notifyStats") val notifyStats: NotifyStats? = null
)

data class NotifyStats(
    val queued: Int = 0,
    val processing: Int = 0,
    val sent: Int = 0,
    val dead: Int = 0,
    val depth: Int = 0
)

/**
 * Shapes below mirror what the PHP backend actually emits from
 * `AdminDashboardApiAction` — `TransactionRepository::statsAll()` returns
 * `{total, credit, debit}` and `ServiceOrderRepository::openOrders()` returns
 * `{count, amount}`. Declaring `openOrders` as a bare `Int` made Gson throw
 * "Expected an int but was BEGIN_OBJECT", which turned a perfectly good 200
 * into the dashboard's error state.
 */
data class TxStats(
    val total: Int = 0,
    val credit: Double = 0.0,
    val debit: Double = 0.0
)

data class OpenOrders(
    val count: Int = 0,
    val amount: Double = 0.0
)

data class TopupStats(
    val pending: Int = 0,
    val approved: Int = 0,
    val rejected: Int = 0
)

data class ActivityLog(
    val id: Int = 0,
    val action: String = "",
    val description: String? = null,
    @SerializedName("ip_address") val ipAddress: String? = null,
    @SerializedName("created_at") val createdAt: String? = null
)

/**
 * Paged list envelope used by list endpoints.
 */
data class PagedResponse<T>(
    val rows: List<T> = emptyList(),
    val total: Int = 0,
    val page: Int = 1,
    @SerializedName("perPage") val perPage: Int = 20,
    @SerializedName("trashedCount") val trashedCount: Int? = null
)

/**
 * Orders list row.
 */
data class AdminOrder(
    val id: Int = 0,
    val status: String = "pending",
    // The joined row aliases the service name as `service_name`.
    @SerializedName("service_name") val service: String? = null,
    val username: String? = null,
    val phone: String? = null,
    @SerializedName("user_id") val userId: Int = 0,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("claimed_by") val claimedBy: Int? = null,
    @SerializedName("claimed_by_name") val claimedByName: String? = null,
    val price: String? = null,
    val reference: String? = null,
    @SerializedName("admin_note") val adminNote: String? = null,
    @SerializedName("cancellation_reason") val cancellationReason: String? = null
)

data class AdminProfile(
    val id: Int,
    val username: String,
    @SerializedName("full_name") val fullName: String? = null,
    @SerializedName("display_name") val displayName: String? = null,
    val phone: String? = null,
    val email: String? = null,
    val role: String,
    val balance: Double = 0.0,
    val avatar: String? = null
)

data class OrderDetailUser(
    val id: Int = 0,
    val name: String? = null,
    val phone: String? = null,
    val email: String? = null
)

data class OrderResultEntry(
    val key: String? = null,
    val label: String? = null,
    val value: String? = null
)

data class OrderDetailEnvelope(
    val order: AdminOrder,
    val user: OrderDetailUser? = null,
    val metadata: Map<String, Any?>? = null,
    @SerializedName("canDecide") val canDecide: Boolean = false,
    @SerializedName("isMine") val isMine: Boolean = false,
    val claimer: OrderDetailUser? = null,
    val approver: OrderDetailUser? = null,
    @SerializedName("resultEntries") val resultEntries: List<OrderResultEntry>? = null
)

/**
 * Topup / recharge row.
 */
data class AdminTopup(
    val id: Int = 0,
    val username: String? = null,
    val phone: String? = null,
    val amount: String = "0.00",
    val status: String = "pending",
    val method: String? = null,
    val reference: String? = null,
    @SerializedName("sender_number") val senderNumber: String? = null,
    val note: String? = null,
    @SerializedName("created_at") val createdAt: String? = null
)

/**
 * Withdraw row.
 */
data class AdminWithdraw(
    val id: Int = 0,
    val username: String? = null,
    val phone: String? = null,
    val amount: String = "0.00",
    val status: String = "pending",
    @SerializedName("admin_id") val adminId: Int = 0,
    @SerializedName("admin_balance") val adminBalance: String? = null,
    @SerializedName("reviewed_by") val reviewedBy: Int? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("reviewed_at") val reviewedAt: String? = null
)

/**
 * Admin notification queue row.
 */
data class AdminNotification(
    val id: Int = 0,
    // notification_queue has no subject column: the event key is the label,
    // and the human text lives inside the JSON `payload` string.
    val event: String? = null,
    val channel: String? = null,
    val status: String = "",
    val attempts: Int? = null,
    @SerializedName("last_error") val lastError: String? = null,
    val payload: String? = null,
    @SerializedName("created_at") val createdAt: String? = null
)

/**
 * Device registration (from /api/devices).
 */
data class Device(
    val id: Int,
    @SerializedName("device_token") val deviceToken: String? = null,
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
