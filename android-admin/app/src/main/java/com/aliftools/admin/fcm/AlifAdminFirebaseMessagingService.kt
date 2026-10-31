package com.aliftools.admin.fcm

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import com.aliftools.admin.MainActivity
import com.aliftools.admin.R
import com.aliftools.admin.data.local.NotificationRetryHandler
import com.aliftools.admin.data.repository.AdminRepository
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import javax.inject.Inject

@AndroidEntryPoint
class AlifAdminFirebaseMessagingService : FirebaseMessagingService() {

    @Inject
    lateinit var repository: AdminRepository

    @Inject
    lateinit var notificationRetryHandler: NotificationRetryHandler

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    override fun onMessageReceived(message: RemoteMessage) {
        try {
            super.onMessageReceived(message)
            Log.d(TAG, "FCM message received: ${message.data}")

            val data = message.data
            val title = data["title"]
                ?: message.notification?.title
                ?: try { getString(R.string.app_name) } catch (e: Exception) { "Alif Tools Admin" }
            val body = data["body"] ?: message.notification?.body
            if (body.isNullOrBlank()) return

            showNotification(
                title = title,
                body = body,
                route = data["route"],
                id = (message.messageId ?: message.data["id"] ?: "").hashCode(),
            )
        } catch (e: Exception) {
            Log.e(TAG, "Error handling FCM message", e)
        }
    }

    private fun showNotification(title: String, body: String, route: String?, id: Int) {
        try {
            val channelId = try { getString(R.string.default_channel_id) } catch (e: Exception) { "alif_admin_alerts" }
            val manager = getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager ?: return

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                try {
                    manager.createNotificationChannel(
                        NotificationChannel(
                            channelId,
                            "অ্যাডমিন অ্যালার্ট",
                            NotificationManager.IMPORTANCE_HIGH,
                        ),
                    )
                } catch (e: Exception) {
                    Log.w(TAG, "Failed to create notification channel", e)
                }
            }

            val intent = Intent(this, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
                route?.let { putExtra(MainActivity.EXTRA_ROUTE, it) }
            }
            val pendingIntent = PendingIntent.getActivity(
                this,
                id,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )

            val notification = NotificationCompat.Builder(this, channelId)
                .setSmallIcon(try { R.drawable.ic_stat_notify } catch (e: Exception) { android.R.drawable.ic_dialog_info })
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(NotificationCompat.BigTextStyle().bigText(body))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setDefaults(NotificationCompat.DEFAULT_ALL)
                .setAutoCancel(true)
                .setContentIntent(pendingIntent)
                .build()

            try {
                manager.notify(id, notification)
            } catch (e: Exception) {
                val payload = mapOf(
                    "title" to title,
                    "body" to body,
                    "route" to route,
                    "id" to id.toString()
                )
                val jsonPayload = payload.toString()
                serviceScope.launch {
                    try {
                        notificationRetryHandler.addFailedNotification(
                            notificationId = id,
                            payload = jsonPayload,
                            route = route,
                            title = title,
                            body = body
                        )
                    } catch (ex: Exception) {
                        Log.w(TAG, "Failed to queue notification for retry", ex)
                    }
                }
                Log.w(TAG, "Initial notification failed, queued for retry", e)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error showing notification", e)
        }
    }

    override fun onNewToken(token: String) {
        try {
            super.onNewToken(token)
            Log.d(TAG, "FCM token refreshed: $token")

            serviceScope.launch {
                try {
                    repository.registerDevice(token, "Android Admin")
                } catch (e: Exception) {
                    Log.w(TAG, "Failed to register FCM token", e)
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error handling new FCM token", e)
        }
    }

    companion object {
        private const val TAG = "AlifAdminFCM"
    }
}
