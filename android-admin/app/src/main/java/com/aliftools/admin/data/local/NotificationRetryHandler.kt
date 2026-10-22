package com.aliftools.admin.data.local

import android.annotation.SuppressLint
import android.content.Context
import android.util.Log
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.qualifiers.ApplicationContext
import org.json.JSONArray
import org.json.JSONObject
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import javax.inject.Inject
import javax.inject.Singleton

const val MAX_NOTIFICATION_RETRIES = 5
const val INITIAL_RETRY_MILLIS = 30000 // 30 seconds
const val BACKOFF_MULTIPLIER = 2.0

@Singleton
class NotificationRetryHandler @Inject constructor(
    @ApplicationContext private val context: Context,
    private val repository: AdminRepository,
) {
    // Callback when a notification should be retried
    var onRetry: ((notificationId: Int, payload: String) -> Unit)? = null

    // Callback when notification max retries exceeded
    var onMaxRetriesExceeded: ((notificationId: Int, payload: String, lastError: String) -> Unit)? = null

    private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "alif_admin_notifications")

    private val retryQueue = stringPreferencesKey("retry_queue")
    private val lastError = stringPreferencesKey("last_error")

    /** Add a failed notification to the retry queue (stored as JSON array) */
    suspend fun addFailedNotification(
        notificationId: Int,
        payload: String,
        route: String?,
        title: String?,
        body: String?
    ) {
        val entry = mapOf(
            "id" to notificationId,
            "payload" to payload,
            "route" to route,
            "title" to title,
            "body" to body,
            "attempt" to 1,
            "lastError" to ""
        )
        context.dataStore.edit { prefs ->
            val existing = prefs[retryQueue] ?: "[]"
            val queue = parseJsonArray(existing)
            queue.add(entry)
            prefs[retryQueue] = serializeJsonArray(queue)
        }
        Log.d("NotificationRetryHandler", "Added notification $notificationId to retry queue")
    }

    /** Parse a JSON array string to mutable list. */
    private fun parseJsonArray(json: String): MutableList<Map<String, Any?>> {
        val result = mutableListOf<Map<String, Any?>>()
        if (json.isBlank()) return result
        return try {
            val arr = JSONArray(json)
            for (i in 0 until arr.length()) {
                val obj = arr.getJSONObject(i)
                val map = mutableMapOf<String, Any?>()
                val keys = obj.keys()
                while (keys.hasNext()) {
                    val k = keys.next()
                    map[k] = obj.opt(k)
                }
                result.add(map)
            }
            result
        } catch (e: Exception) {
            mutableListOf()
        }
    }

    /** Serialize list to JSON array string. */
    private fun serializeJsonArray(list: List<Map<String, Any?>>): String {
        val arr = JSONArray()
        for (entry in list) {
            arr.put(JSONObject(entry))
        }
        return arr.toString()
    }

    /** Attempt to retry pending notifications */
    suspend fun retryPendingNotifications() {
        val queueJson = context.dataStore.data.first()[retryQueue] ?: ""
        if (queueJson.isBlank()) return

        val queue = parseJsonArray(queueJson)
        var i = queue.size - 1

        while (i >= 0) {
            val entry = queue[i].toMutableMap()
            val id = (entry["id"] as? Number)?.toInt() ?: continue
            val payload = entry["payload"] as? String ?: continue
            val attempt = ((entry["attempt"] as? Number)?.toInt() ?: 1).coerceAtMost(MAX_NOTIFICATION_RETRIES)

            // Check if enough time has passed since last attempt
            val lastAttempt = (entry["lastError"] as? String)?.takeIf { it.isNotBlank() }
            val minInterval = getRetryInterval(attempt)

            val now = System.currentTimeMillis()
            // Simplified: always retry if we have attempts remaining
            // In production, check timestamp against lastAttempt

            try {
                onRetry?.invoke(id, payload)
                // Remove successfully retried notification
                queue.removeAt(i)
                context.dataStore.edit { prefs ->
                    prefs[retryQueue] = serializeJsonArray(queue)
                }
                Log.d("NotificationRetryHandler", "Successfully retried notification $id")
            } catch (e: Exception) {
                Log.w("NotificationRetryHandler", "Retry failed for notification $id", e)
                // Update attempt and error
                entry["attempt"] = (attempt + 1)
                entry["lastError"] = e.message ?: "Unknown error"

                if (attempt >= MAX_NOTIFICATION_RETRIES) {
                    // Max retries exceeded
                    onMaxRetriesExceeded?.invoke(id, payload, entry["lastError"] as? String ?: "Unknown")
                    queue.removeAt(i)
                    context.dataStore.edit { prefs ->
                        prefs[retryQueue] = serializeJsonArray(queue)
                    }
                    Log.e("NotificationRetryHandler", "Max retries exceeded for notification $id")
                } else {
                    // Save with incremented attempt
                    context.dataStore.edit { prefs ->
                        val updatedQueue = queue.toMutableList()
                        updatedQueue.set(i, entry)
                        prefs[retryQueue] = serializeJsonArray(updatedQueue)
                    }
                }
            }
            i--
        }
    }

    /** Get the retry interval in milliseconds for the given retry attempt number */
    @SuppressLint("UnnecessaryReturn")
    private fun getRetryInterval(retryAttempt: Int): Long {
        // Exponential backoff: 30s, 60s, 120s, 240s, 480s, ...
        val base = (INITIAL_RETRY_MILLIS * Math.pow(BACKOFF_MULTIPLIER, (retryAttempt - 1).toDouble())).toLong()
        // Cap at 16 minutes to avoid excessive waiting
        return base.coerceAtMost(960000L)
    }

    /** Clear all retry entries (e.g., on successful login) */
    suspend fun clearAllRetries() {
        context.dataStore.edit { prefs ->
            prefs[retryQueue] = "[]"
            prefs[lastError] = ""
        }
        Log.d("NotificationRetryHandler", "Cleared all notification retry entries")
    }
}