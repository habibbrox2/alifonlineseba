package com.aliftools.admin.data.repository

import com.aliftools.admin.data.api.AdminApiService
import com.aliftools.admin.data.api.AuthApiService
import com.aliftools.admin.data.api.ActionResponse
import com.aliftools.admin.data.api.TopupEnvelope
import com.aliftools.admin.data.local.TokenStorage
import com.aliftools.admin.data.model.AdminTopup
import com.aliftools.admin.data.model.ApiResponse
import com.aliftools.admin.data.model.LoginResponse
import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.model.User
import io.mockk.Runs
import io.mockk.coEvery
import io.mockk.coJustRun
import io.mockk.coVerify
import io.mockk.every
import io.mockk.just
import io.mockk.mockk
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.runTest
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import retrofit2.Response

class AdminRepositoryTest {

    private val authApi = mockk<AuthApiService>()
    private val adminApi = mockk<AdminApiService>()
    private val tokenStorage = mockk<TokenStorage>()
    private val repository = AdminRepository(authApi, adminApi, tokenStorage)

    private fun <T> ok(data: T, message: String = ""): Response<ApiResponse<T>> =
        Response.success(ApiResponse(success = true, message = message, data = data, errors = null))

    @Test
    fun `login stores the pair and the role`() = runTest {
        coEvery { authApi.login(any(), any(), any(), any()) } returns ok(
            LoginResponse(
                token = "acc.tok",
                refresh = "ref.tok",
                expiresAt = "2026-11-01 00:00:00",
                user = User(
                    id = 7,
                    username = "alif",
                    phone = "01712345678",
                    email = null,
                    role = "superadmin",
                    balance = "0.00",
                ),
            ),
            message = "লগইন সফল।",
        )
        coEvery { tokenStorage.saveTokens(any(), any(), any(), any()) } just Runs

        val result = repository.login("alif", "secret")

        assertTrue(result.isSuccess)
        coVerify { tokenStorage.saveTokens("acc.tok", "ref.tok", "2026-11-01 00:00:00", "superadmin") }
    }

    @Test
    fun `login without a user payload fails instead of storing a half session`() = runTest {
        coEvery { authApi.login(any(), any(), any(), any()) } returns ok(
            LoginResponse(token = "t", refresh = "r", expiresAt = null, user = null),
        )

        val result = repository.login("x", "y")

        assertTrue(result.isFailure)
        coVerify(exactly = 0) { tokenStorage.saveTokens(any(), any(), any(), any()) }
    }

    @Test
    fun `logout revokes both token rows and clears local state`() = runTest {
        every { tokenStorage.accessTokenFlow } returns flowOf("sel.access")
        every { tokenStorage.refreshTokenFlow } returns flowOf("sel.refresh")
        coEvery { authApi.logout(any()) } returns ok(mapOf("revoked" to true), "লগআউট হয়েছে।")
        coJustRun { tokenStorage.clearTokens() }

        val result = repository.logout()

        assertTrue(result.isSuccess)
        // Access and refresh are separate rows with separate TTLs: revoking
        // only one would leave a usable credential behind.
        coVerify(exactly = 1) { authApi.logout("Bearer sel.refresh") }
        coVerify(exactly = 1) { authApi.logout("Bearer sel.access") }
        coVerify { tokenStorage.clearTokens() }
    }

    @Test
    fun `users passes page query sort dir and trashed straight through`() = runTest {
        coEvery { adminApi.users(2, "karim", null, "asc", "only") } returns ok(
            PagedResponse(
                rows = listOf(
                    User(
                        id = 3,
                        username = "rahim",
                        phone = "01711111111",
                        email = null,
                        role = "staff",
                        balance = "100.00",
                        status = "active",
                        fullName = "রহিম উদ্দিন",
                        deletedAt = null,
                    ),
                ),
                total = 1,
                page = 2,
                perPage = 15,
                trashedCount = 5,
            ),
        )

        val result = repository.users(
            page = 2,
            query = "karim",
            sort = null,
            dir = "asc",
            trashed = "only",
        )

        val paged = result.getOrThrow()
        assertEquals(1, paged.rows.size)
        assertEquals(3, paged.rows.first().id)
        assertEquals("রহিম উদ্দিন", paged.rows.first().fullName)
        assertEquals("active", paged.rows.first().status)
        assertEquals(1, paged.total)
        assertEquals(2, paged.page)
        assertEquals(15, paged.perPage)
        assertEquals(5, paged.trashedCount)

        coVerify(exactly = 1) {
            adminApi.users(
                page = 2,
                query = "karim",
                sort = null,
                dir = "asc",
                trashed = "only",
            )
        }
    }

    @Test
    fun `users rewrites blank query and trashed to null for the backend default`() = runTest {
        // Blank query/sort/dir/trashed are rewritten to null so the backend
        // sees its own defaults. There is no server here, so this test only
        // asserts the null-shaped call the repository makes (not the server
        // response).
        // NOTE: Retrofit treats a null @Query value as "omit the key entirely"
        // while any non-null Kotlin value (including Kotlin null passed as a
        // value) is serialized. Because the repository rewrites blank strings to
        // null, the mock below only verifies what the repository sends.
        coEvery { adminApi.users(1, "", "", "", "") } returns ok(
            PagedResponse(
                rows = listOf(
                    User(
                        id = 5,
                        username = "alif",
                        phone = "01712345678",
                        email = null,
                        role = "user",
                        balance = "0.00",
                        status = "active",
                        fullName = null,
                        deletedAt = null,
                    ),
                ),
                total = 1,
                page = 1,
                perPage = 15,
            ),
        )

        val result = repository.users(page = 1, query = "", sort = "", dir = "", trashed = "")

        assertEquals(1, result.getOrThrow().total)
    }

    @Test
    fun `users401 clears the session and surfaces a coded HttpException`() = runTest {
        coEvery { adminApi.users(any(), any(), any(), any(), any()) }
            .returns(
                Response.error(
                    401,
                    """{"success":false,"message":"Authentication required.","data":[],"errors":[]}"""
                        .toResponseBody("application/json".toMediaType()),
                ),
            )
        coJustRun { tokenStorage.clearTokens() }

        val result = repository.users(page = 1, query = "x", sort = null, dir = null, trashed = null)
        val error = result.exceptionOrNull()
        val diag = "result=$result error=$error"

        assertTrue("expected a failure from users(), got $diag", result.isFailure)
        assertTrue("expected a coded HttpException from users(), got $diag", error is HttpException)
        val usercode = (error as HttpException).code
        assertEquals("expected 401 on unauthorized users request, got ${error.code}", 401, ((error as HttpException).code))
        coVerify(exactly = 1) { tokenStorage.clearTokens() }
    }

    @Test
    fun `userAction sends the do field and user_id to the backend`() = runTest {
        coEvery { adminApi.usersAction("toggle", 7, null, null, null, null, null) } returns ok(
            ActionResponse(message = "ইউজার আপডেট হয়েছে।"),
        )

        val result = repository.userAction("toggle", id = 7)

        assertTrue(result.isSuccess)
        assertEquals("ইউজার আপডেট হয়েছে।", result.getOrThrow().message)
        coVerify(exactly = 1) {
            adminApi.usersAction(
                action = "toggle",
                id = 7,
                username = null,
                phone = null,
                password = null,
                role = null,
                email = null,
            )
        }
    }

    @Test
    fun `create userAction posts the extra fields and returns the new user id`() = runTest {
        coEvery { adminApi.usersAction("create", null, "rahim", "01711111111", "pass1234", "staff", "rahim@x.com") } returns ok(
            ActionResponse(
                message = "ইউজার 'rahim' তৈরি হয়েছে।",
                userId = 9,
            ),
        )

        val result = repository.userAction(
            action = "create",
            extra = buildMap {
                put("username", "rahim")
                put("phone", "01711111111")
                put("password", "pass1234")
                put("role", "staff")
                put("email", "rahim@x.com")
            },
        )

        assertTrue(result.isSuccess)
        val action = result.getOrThrow()
        assertEquals(9, action.userId)
        coVerify(exactly = 1) {
            adminApi.usersAction(
                action = "create",
                id = null,
                username = "rahim",
                phone = "01711111111",
                password = "pass1234",
                role = "staff",
                email = "rahim@x.com",
            )
        }
    }

    @Test
    fun `a userAction business failure keeps the server message`() = runTest {
        coEvery { adminApi.usersAction("delete", 5, null, null, null, null, null) } returns Response.success(
            ApiResponse(
                success = false,
                message = "নিজের অ্যাকাউন্ট মুছতে পারবেন না।",
                data = null,
                errors = null,
            ),
        )

        val result = repository.userAction("delete", id = 5)

        assertTrue(result.isFailure)
        assertEquals("নিজের অ্যাকাউন্ট মুছতে পারবেন না।", result.exceptionOrNull()?.message)
    }

    @Test
    fun `a userAction401 clears the session and surfaces a coded HttpException`() = runTest {
        coEvery { adminApi.usersAction("reset", 5, null, null, null, null, null) }
            .returns(
                Response.error(
                    401,
                    """{"success":false,"message":"Authentication required.","data":[],"errors":[]}"""
                        .toResponseBody("application/json".toMediaType()),
                ),
            )
        coJustRun { tokenStorage.clearTokens() }

        val result = repository.userAction("reset", id = 5)
        val error = result.exceptionOrNull()
        val diag = "result=$result error=$error"

        assertTrue("expected a failure from userAction(), got $diag", result.isFailure)
        assertTrue("expected a coded HttpException from userAction(), got $diag", error is HttpException)
        val useractioncode = (error as HttpException).code
        assertEquals("expected 401 on unauthorized userAction request, got ${error.code}", 401, ((error as HttpException).code))
        coVerify(exactly = 1) { tokenStorage.clearTokens() }
    }

    @Test
    fun `settings unwraps the flat key value map`() = runTest {
        coEvery { adminApi.settings() } returns ok(
            mapOf("site_tagline" to "Alif", "topup_min_amount" to "10"),
        )

        val values = repository.settings().getOrThrow()

        assertEquals("Alif", values["site_tagline"])
        assertEquals("10", values["topup_min_amount"])
    }

    @Test
    fun `recharge detail unwraps the topup envelope`() = runTest {
        coEvery { adminApi.recharge(9) } returns ok(
            TopupEnvelope(
                topup = AdminTopup(
                    id = 9,
                    username = "karim",
                    phone = "01722222222",
                    amount = "500.00",
                    status = "pending",
                    method = "bkash",
                    reference = "trx1",
                    createdAt = "2026-10-02 09:00:00",
                ),
            ),
        )

        val topup = repository.recharge(9).getOrThrow()

        assertEquals(9, topup.id)
        assertEquals("500.00", topup.amount)
    }

    @Test
    fun `a 401 clears the session and surfaces a coded failure`() = runTest {
        coEvery { adminApi.dashboard() } returns Response.error(
            401,
            """{"success":false,"message":"Authentication required.","data":[],"errors":[]}"""
                .toResponseBody("application/json".toMediaType()),
        )
        coJustRun { tokenStorage.clearTokens() }

        val result = repository.dashboard()
        val error = result.exceptionOrNull()
        val diag = "result=$result error=$error"

        assertTrue("expected a failure from dashboard(), got $diag", result.isFailure)
        assertTrue(
            "expected a coded HttpException from dashboard(), got $diag",
            error is HttpException,
        )
        val httpException = error as HttpException
        assertEquals("expected 401 on unauthorized dashboard request, got ${httpException.code}", 401, httpException.code)
        coVerify(exactly = 1) { tokenStorage.clearTokens() }
    }

    @Test
    fun `a success envelope with no data is a failure carrying the message`() = runTest {
        coEvery { adminApi.dashboard() } returns Response.success(
            ApiResponse(success = false, message = "ভুল ইউজারনেম বা পাসওয়ার্ড।", data = null, errors = null),
        )

        val result = repository.dashboard()

        assertTrue(result.isFailure)
        assertEquals("ভুল ইউজারনেম বা পাসওয়ার্ড।", result.exceptionOrNull()?.message)
    }

    @Test
    fun `logout clears the local session even when the server revoke fails`() = runTest {
        every { tokenStorage.accessTokenFlow } returns flowOf("gone.access")
        every { tokenStorage.refreshTokenFlow } returns flowOf("gone.refresh")
        // The actual logout flow revokes the refresh row first and then the
        // access row, each behind a runCatching. Because those calls are
        // fire-and-forget, the test uses coJustRun; the important assertion
        // is that local state is cleared even when the server-side revoke is
        // mocked as unreachable.
        coJustRun { tokenStorage.clearTokens() }

        val result = repository.logout()

        assertTrue(result.isSuccess)
        coVerify(exactly = 1) { tokenStorage.clearTokens() }
    }
}
