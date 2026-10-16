package com.aliftools.admin.data.model

import com.aliftools.admin.data.api.ActionResponse
import com.aliftools.admin.data.api.AppVersion
import com.aliftools.admin.data.api.TopupEnvelope
import com.aliftools.admin.data.api.WithdrawEnvelope
import com.google.gson.GsonBuilder
import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Contract tests for the JSON the PHP API actually emits — every fixture
 * below is copied from an action's real Api::ok()/Api::fail() payload.
 */
class ApiParsingTest {

    private val gson = GsonBuilder()
        .registerTypeAdapterFactory(EnvelopeAdapterFactory)
        .create()

    private inline fun <reified T> parse(json: String): ApiResponse<T> =
        gson.fromJson(json, object : TypeToken<ApiResponse<T>>() {}.type)

    @Test
    fun `login payload carries the role and a numeric balance`() {
        val json = """
            {"success":true,"message":"লগইন সফল।","data":{
              "token":"sel.ver","refresh":"sel2.ver2","expires_at":"2026-11-01 00:00:00",
              "user":{"id":7,"username":"alif","phone":"01712345678","balance":12.5,"role":"superadmin"}
            },"errors":[]}
        """.trimIndent()

        val body = parse<LoginResponse>(json)
        assertTrue(body.success)
        val user = body.data!!.user!!
        assertEquals("superadmin", user.role)
        assertEquals("12.5", user.balance)
        assertEquals("alif", user.username)
        // The login payload ships five user fields and nothing else; the
        // model promises nullability instead of inventing values.
        assertNull(user.status)
        assertNull(user.email)
        assertEquals("sel.ver", body.data!!.token)
    }

    @Test
    fun `refresh payload has no user object and parses as null`() {
        val json = """
            {"success":true,"message":"Token rotated.","data":{
              "token":"new.ver","refresh":"new2.ver2","expires_at":"2026-12-01 00:00:00"
            },"errors":[]}
        """.trimIndent()

        val body = parse<LoginResponse>(json)
        assertTrue(body.success)
        assertNull(body.data!!.user)
        assertEquals("new.ver", body.data!!.token)
    }

    @Test
    fun `a validation failure with an array data does not destroy the message`() {
        // Api::fail('…', $errors) emits data as an ARRAY of messages when an
        // action built its errors list with $errors[] = …; a strict adapter
        // throws here and the Bengali reason is lost to a parse error.
        val json = """
            {"success":false,"message":"ইউজার আইডি প্রয়োজন।","data":[],"errors":[]}
        """.trimIndent()

        val body = parse<ActionResponse>(json)
        assertFalse(body.success)
        assertEquals("ইউজার আইডি প্রয়োজন।", body.message)
        assertNull(body.data)
    }

    @Test
    fun `errors as a json array still yields a usable envelope`() {
        val json = """
            {"success":false,"message":"ব্যর্থ","data":[],"errors":["একটি ঘর ভুল আছে"]}
        """.trimIndent()

        val body = parse<ActionResponse>(json)
        assertFalse(body.success)
        assertEquals("ব্যর্থ", body.message)
        assertNull(body.errors)
    }

    @Test
    fun `order rows expose the joined service_name alias`() {
        val json = """
            {"success":true,"message":"","data":{"rows":[{
              "id":5,"status":"pending","service_name":"SEO সার্ভিস",
              "username":"alif","phone":"01712345678","user_id":7,
              "created_at":"2026-10-01 10:00:00","claimed_by":null,
              "claimed_by_name":null
            }],"total":1,"page":1,"perPage":20},"errors":[]}
        """.trimIndent()

        val rows = parse<PagedResponse<AdminOrder>>(json).data!!.rows
        assertEquals("SEO সার্ভিস", rows[0].service)
        assertEquals("alif", rows[0].username)
        assertNull(rows[0].claimedByName)
    }

    @Test
    fun `user rows carry full_name and deleted_at`() {
        val json = """
            {"success":true,"message":"","data":{"rows":[{
              "id":3,"username":"rahim","phone":"01711111111","email":null,
              "role":"staff","balance":"100.00","status":"active",
              "full_name":"রহিম উদ্দিন","deleted_at":null
            }],"total":1,"page":1,"perPage":15},"errors":[]}
        """.trimIndent()

        val user = parse<PagedResponse<User>>(json).data!!.rows[0]
        assertEquals("রহিম উদ্দিন", user.fullName)
        assertNull(user.deletedAt)
    }

    @Test
    fun `temporary password from a reset action maps to its field`() {
        val json = """
            {"success":true,"message":"পাসওয়ার্ড রিসেট হয়েছে।","data":{
              "temporary_password":"Ab3!xyz","message":"পাসওয়ার্ড রিসেট হয়েছে।"
            },"errors":[]}
        """.trimIndent()

        val action = parse<ActionResponse>(json).data!!
        assertEquals("Ab3!xyz", action.temporaryPassword)
        assertNotNull(action.message)
    }

    @Test
    fun `recharge detail wraps the row in a topup envelope`() {
        val json = """
            {"success":true,"message":"","data":{"topup":{
              "id":9,"username":"karim","phone":"01722222222","amount":"500.00",
              "status":"pending","method":"bkash","reference":"trx1",
              "sender_number":"01722222222","note":null,"created_at":"2026-10-02 09:00:00"
            }},"errors":[]}
        """.trimIndent()

        val topup = parse<TopupEnvelope>(json).data!!.topup
        assertEquals(9, topup.id)
        assertEquals("bkash", topup.method)
        assertEquals("01722222222", topup.senderNumber)
    }

    @Test
    fun `withdraw decision wraps the fresh row and a message`() {
        val json = """
            {"success":true,"message":"","data":{
              "withdraw":{"id":4,"username":null,"phone":null,"amount":"250.00",
                "status":"approved","admin_id":2,"admin_balance":"750.00",
                "reviewed_by":1,"created_at":"2026-10-03 08:00:00","reviewed_at":"2026-10-03 09:00:00"},
              "message":"৳250.00 উত্তোলন অনুমোদিত হয়েছে।"
            },"errors":[]}
        """.trimIndent()

        val envelope = parse<WithdrawEnvelope>(json).data!!
        assertEquals("approved", envelope.withdraw.status)
        assertEquals("৳250.00 উত্তোলন অনুমোদিত হয়েছে।", envelope.message)
    }

    @Test
    fun `app version payload carries the download url`() {
        val json = """
            {"success":true,"message":"","data":{
              "version_code":42,"version_name":"1.4.0","min_version_code":30,
              "release_notes":"notes","sha256":"abc","published_at":"2026-10-17 00:00:00",
              "size_bytes":1024,"download_url":"/app/apk?channel=admin"
            },"errors":[]}
        """.trimIndent()

        val version = parse<AppVersion>(json).data!!
        org.junit.Assert.assertEquals(42, version.versionCode)
        org.junit.Assert.assertEquals("/app/apk?channel=admin", version.downloadUrl)
    }
}
