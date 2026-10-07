package com.aliftools.admin.data.api

import com.aliftools.admin.data.model.*
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
        @Field("device_label") deviceLabel: String? = "Android Admin"
    ): Response<ApiResponse<LoginResponse>>

    @FormUrlEncoded
    @POST("api/auth/refresh")
    suspend fun refresh(
        @Field("refresh") refreshToken: String
    ): Response<ApiResponse<LoginResponse>>

    @POST("api/auth/logout")
    suspend fun logout(): Response<ApiResponse<Unit>>
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
        @Query("trashed") trashed: Int? = null
    ): Response<ApiResponse<PagedResponse<User>>>

    @FormUrlEncoded
    @POST("api/admin/users")
    suspend fun usersAction(
        @Field("do") action: String,
        @Field("id") id: Int? = null,
        @Field("username") username: String? = null,
        @Field("phone") phone: String? = null,
        @Field("password") password: String? = null,
        @Field("role") role: String? = null,
        @Field("full_name") fullName: String? = null
    ): Response<ApiResponse<Unit>>

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
        @Field("ids") ids: List<Int>,
        @Field("status") status: String
    ): Response<ApiResponse<Unit>>

    @GET("api/admin/topups")
    suspend fun topups(
        @Query("page") page: Int = 1,
        @Query("status") status: String? = null,
        @Query("q") query: String? = null
    ): Response<ApiResponse<PagedResponse<AdminTopup>>>

    @GET("api/admin/recharges/{id}")
    suspend fun recharge(@Path("id") id: Int): Response<ApiResponse<AdminTopup>>

    @FormUrlEncoded
    @POST("api/admin/recharges/{id}")
    suspend fun rechargeAction(
        @Path("id") id: Int,
        @Field("do") action: String,
        @Field("reason") reason: String? = null
    ): Response<ApiResponse<AdminTopup>>

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
    ): Response<ApiResponse<AdminWithdraw>>

    @GET("api/admin/staff")
    suspend fun staff(
        @Query("page") page: Int = 1,
        @Query("q") query: String? = null
    ): Response<ApiResponse<PagedResponse<User>>>

    @GET("api/admin/settings")
    suspend fun settings(): Response<ApiResponse<List<Setting>>>

    @FormUrlEncoded
    @POST("api/admin/settings")
    suspend fun saveSettings(
        @FieldMap values: Map<String, String>
    ): Response<ApiResponse<Unit>>

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
    suspend fun retryNotification(@Path("id") id: Int): Response<ApiResponse<Unit>>

    @POST("api/devices")
    suspend fun registerDevice(
        @Body request: Map<String, String>
    ): Response<ApiResponse<Unit>>

    @GET("api/devices")
    suspend fun devices(): Response<ApiResponse<List<Device>>>

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
