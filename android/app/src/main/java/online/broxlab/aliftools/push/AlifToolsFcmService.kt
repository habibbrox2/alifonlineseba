package online.broxlab.aliftools.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import online.broxlab.aliftools.MainActivity
import online.broxlab.aliftools.R

/**
 * FCM receiver (audit Phase 2). The worker sends data payloads —
 * `{event, title, body, link, tx_id}` — so the client owns rendering and the
 * tap intent. Deep-link PendingIntents are FLAG_IMMUTABLE with a per-message
 * request code, or Android merges taps and opens the wrong screen (§7).
 */
class AlifToolsFcmService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        // Ship it to the backend; the upsert is idempotent per token.
        DeviceRegistrar.enqueueRegistration(applicationContext, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val title = data["title"] ?: message.notification?.title ?: getString(R.string.app_name)
        val body = data["body"] ?: message.notification?.body ?: return

        val deepLink = DeepLink.fromData(data)
        showNotification(message.messageId ?: data["dedupe_key"] ?: title, title, body, deepLink)
    }

    private fun showNotification(tag: String, title: String, body: String, link: DeepLink?) {
        val manager = getSystemService(NotificationManager::class.java)

        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_ID, "সাধারণ", NotificationManager.IMPORTANCE_DEFAULT),
        )

        val contentIntent = when (link) {
            null -> PendingIntent.getActivity(
                this, 0,
                Intent(this, MainActivity::class.java),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
            else -> PendingIntent.getActivity(
                // Unique request code per notification: taps must not merge.
                this, tag.hashCode(),
                Intent(Intent.ACTION_VIEW, Uri.parse(link.uri())).setClass(this, MainActivity::class.java),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
        }

        manager.notify(
            tag.hashCode(),
            NotificationCompat.Builder(this, CHANNEL_ID)
                .setSmallIcon(android.R.drawable.stat_notify_chat)
                .setContentTitle(title)
                .setContentText(body)
                .setAutoCancel(true)
                .setContentIntent(contentIntent)
                .build(),
        )
    }

    private fun DeepLink.uri(): String = when (this) {
        is DeepLink.ServiceRequest -> "aliftools://requests/$id"
    }

    private companion object {
        const val CHANNEL_ID = "general"
    }
}
