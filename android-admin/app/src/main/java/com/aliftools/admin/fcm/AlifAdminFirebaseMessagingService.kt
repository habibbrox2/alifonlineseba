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
import kotlinx.coroutines.MainCoroutineDispatcher
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
        super.onMessageReceived(message)
        Log.d(TAG, "FCM message received: ${message.data}")

        val data = message.data
        val title = data["title"]
            ?: message.notification?.title
            ?: getString(R.string.app_name)
        // Notification-payload messages carry no `body` key; they fall back
        // to the platform's own `body`. A message with neither has nothing
        // worth showing.
        val body = data["body"] ?: message.notification?.body
        if (body.isNullOrBlank()) return

        // Attempt to show the notification; if fails, queue for retry
        showNotification(
            title = title,
            body = body,
            route = data["route"],
            id = (message.messageId ?: message.data["id"] ?: "").hashCode(),
        )
    }

    private fun showNotification(title: String, body: String, route: String?, id: Int) {
        val channelId = getString(R.string.default_channel_id)
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(
                    channelId,
                    "অ্যাডমিন অ্যালার্ট",
                    NotificationManager.IMPORTANCE_HIGH,
                ),
            )
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
            .setSmallIcon(R.drawable.ic_stat_notify)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setDefaults(NotificationCompat.DEFAULT_ALL)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()

        // Try to notify immediately; if fails, queue for retry
        try {
            manager.notify(id, notification)
        } catch (e: Exception) {
            // Notification failed (e.g., permission denied, channel error)
            val payload = mapOf(
                "title" to title,
                "body" to body,
                "route" to route,
                "id" to id.toString()
            )
            val jsonPayload = payload.toString()
            serviceScope.launch {
                notificationRetryHandler.addFailedNotification(
                    notificationId = id,
                    payload = jsonPayload,
                    route = route,
                    title = title,
                    body = body
                )
            }
            Log.w(TAG, "Initial notification failed, queued for retry", e)
        }
    }

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        Log.d(TAG, "FCM token refreshed: $token")

        serviceScope.launch {
            try {
                repository.registerDevice(token, "Android Admin")
            } catch (e: Exception) {
                Log.w(TAG, "Failed to register FCM token", e)
            }
        }
    }

    companion object {
        private const val TAG = "AlifAdminFCM"
    }
}