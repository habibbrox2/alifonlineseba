package com.aliftools.admin.data.api

import com.aliftools.admin.data.model.*
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.*

/**
 * Machine auth — bearer/session optional.
 */
interface AuthApiService {
    @FormUrlEncoded
    @POST("api/auth/login")
    suspend fun login(
        @Field("identifier") identifier: String,
        @Field("password") password: String,
        @Field("device_label") deviceLabel: String? = "Android Admin",
        // Proof-of-origin for the CSRF gate: a cross-site HTML form cannot
        // set an Authorization header, so its presence lets the server skip
        // token validation for machine clients that hold no session cookie.
        // /api/auth/* ignores the header for its own logic.
        @Header("Authorization") origin: String = "Bearer android-admin"
    ): Response<ApiResponse<LoginResponse>>

    // No @FormUrlEncoded here, and that matters: the token rides in the
    // Authorization header, so there is no form body to send — and Retrofit
    // rejects a @FormUrlEncoded method that declares no @Field by throwing
    // before the request is ever built. That made every silent refresh fail
    // and hand the user a 401 they could only fix by logging in again.
    @POST("api/auth/refresh")
    suspend fun refresh(
        @Header("Authorization") bearer: String
    ): Response<ApiResponse<LoginResponse>>

    @POST("api/auth/logout")
    suspend fun logout(
        @Header("Authorization") bearer: String
    ): Response<ApiResponse<Map<String, Boolean>>>

    /**
     * Exchanges a Firebase ID token for an admin token pair.
     *
     * The server verifies the token against Google's certs, then provisions or
     * matches an account by verified email/phone — no password is ever sent.
     * `firebase_provider` tells it which provider produced the token so the
     * activity log records how the account was reached.
     */
    @FormUrlEncoded
    @POST("api/auth/firebase")
    suspend fun firebaseLogin(
        @Field("id_token") idToken: String,
        @Field("firebase_provider") provider: String = "phone",
        @Field("device_label") deviceLabel: String? = "Android Admin",
        // Same proof-of-origin header as password login; see the note there.
        @Header("Authorization") origin: String = "Bearer android-admin"
    ): Response<ApiResponse<LoginResponse>>
}

/**
 * Admin JSON API — bearer OR session.
 */
interface AdminApiService {

    @GET("api/admin/dashboard")
    suspend fun dashboard(): Response<ApiResponse<AdminDashboard>>

    @GET("api/admin/users")
    suspend fun users(
        @Query("page") page: Int = 1,
        @Query("q") query: String? = null,
        @Query("sort") sort: String? = null,
        @Query("dir") dir: String? = null,
        // "exclude" (default) | "only" | "all" — UserRepository::DELETED_*.
        @Query("trashed") trashed: String? = null
    ): Response<ApiResponse<PagedResponse<User>>>

    @FormUrlEncoded
    @POST("api/admin/users")
    suspend fun usersAction(
        @Field("do") action: String,
        // AdminUsersApiAction reads `user_id`, not `id` — on every branch
        // except create.
        @Field("user_id") id: Int? = null,
        @Field("username") username: String? = null,
        @Field("phone") phone: String? = null,
        @Field("password") password: String? = null,
        @Field("role") role: String? = null,
        @Field("email") email: String? = null
    ): Response<ApiResponse<ActionResponse>>

    @GET("api/admin/orders")
    suspend fun orders(
        @Query("page") page: Int = 1,
        @Query("q") query: String? = null,
        @Query("scope") scope: String? = null
    ): Response<ApiResponse<PagedResponse<AdminOrder>>>

    @FormUrlEncoded
    @POST("api/admin/orders")
    suspend fun ordersBulk(
        @Field("do") action: String = "bulk_status",
        // PHP only builds an array from repeated `ids[]` keys; plain `ids`
        // would collapse to the last value and settle a single order.
        @Field("ids[]") ids: List<Int>,
        @Field("status") status: String
    ): Response<ApiResponse<ActionResponse>>

    @GET("api/admin/topups")
    suspend fun topups(
        @Query("page") page: Int = 1,
        @Query("status") status: String? = null,
        @Query("q") query: String? = null
    ): Response<ApiResponse<PagedResponse<AdminTopup>>>

    @GET("api/admin/recharges/{id}")
    suspend fun recharge(@Path("id") id: Int): Response<ApiResponse<TopupEnvelope>>

    @FormUrlEncoded
    @POST("api/admin/recharges/{id}")
    suspend fun rechargeAction(
        @Path("id") id: Int,
        @Field("do") action: String,
        // The backend's reject branch reads `note`, not `reason`.
        @Field("note") reason: String? = null
    ): Response<ApiResponse<ActionResponse>>

    @GET("api/admin/withdraws")
    suspend fun withdraws(
        @Query("page") page: Int = 1,
        @Query("status") status: String? = null,
        @Query("q") query: String? = null
    ): Response<ApiResponse<PagedResponse<AdminWithdraw>>>

    @FormUrlEncoded
    @POST("api/admin/withdraws/{id}")
    suspend fun withdrawAction(
        @Path("id") id: Int,
        @Field("do") action: String,
        @Field("reason") reason: String? = null
    ): Response<ApiResponse<WithdrawEnvelope>>

    @GET("api/admin/staff")
    suspend fun staff(
        @Query("page") page: Int = 1,
        @Query("q") query: String? = null
    ): Response<ApiResponse<PagedResponse<User>>>

    @GET("api/admin/settings")
    suspend fun settings(): Response<ApiResponse<Map<String, String>>>

    @FormUrlEncoded
    @POST("api/admin/settings")
    suspend fun saveSettings(
        @FieldMap values: Map<String, String>
    ): Response<ApiResponse<Map<String, String>>>

    @GET("api/admin/logs")
    suspend fun logs(
        @Query("page") page: Int = 1,
        @Query("perPage") perPage: Int = 25,
        @Query("q") query: String? = null
    ): Response<ApiResponse<PagedResponse<ActivityLog>>>

    @GET("api/admin/notifications")
    suspend fun notifications(
        @Query("page") page: Int = 1,
        @Query("status") status: String? = null
    ): Response<ApiResponse<PagedResponse<AdminNotification>>>

    @POST("api/admin/notifications/{id}/retry")
    suspend fun retryNotification(@Path("id") id: Int): Response<ApiResponse<ActionResponse>>

    // DeviceApiAction reads `token` (not `device_token`) out of the JSON body.
    @POST("api/devices")
    suspend fun registerDevice(
        @Body request: Map<String, String>
    ): Response<ApiResponse<Map<String, Int>>>

    @GET("api/devices")
    suspend fun devices(): Response<ApiResponse<DeviceListEnvelope>>

    @DELETE("api/devices/{id}")
    suspend fun deleteDevice(@Path("id") id: Int): Response<ApiResponse<Unit>>

    @GET("api/app/version")
    suspend fun appVersion(): Response<ApiResponse<AppVersion>>
}

data class AppVersion(
    @SerializedName("version_code") val versionCode: Int,
    @SerializedName("version_name") val versionName: String,
    @SerializedName("min_version_code") val minVersionCode: Int,
    @SerializedName("release_notes") val releaseNotes: String?
)

/** Generic `{message}` / `{temporary_password}` body of a mutating admin action. */
data class ActionResponse(
    val message: String? = null,
    @SerializedName("temporary_password") val temporaryPassword: String? = null,
    @SerializedName("user_id") val userId: Int? = null
)

/** GET /api/admin/recharges/{id} wraps the row: `{topup: {...}}`. */
data class TopupEnvelope(val topup: AdminTopup)

/** POST /api/admin/withdraws/{id} returns `{withdraw, message}`. */
data class WithdrawEnvelope(
    val withdraw: AdminWithdraw,
    val message: String? = null
)

/** GET /api/devices returns `{devices: [...]}`. */
data class DeviceListEnvelope(val devices: List<Device>)
