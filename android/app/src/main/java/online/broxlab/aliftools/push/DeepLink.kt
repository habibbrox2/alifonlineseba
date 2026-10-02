package online.broxlab.aliftools.push

import android.content.Intent
import android.net.Uri

/**
 * Push deep links (audit §7): the worker emits `data.link` as a path like
 * `/requests/10245`; a tap must land on the exact screen, never the home
 * screen. Parsed both from FCM data payloads and from VIEW intents.
 */
sealed interface DeepLink {
    val route: String

    data class ServiceRequest(val id: Long) : DeepLink {
        override val route: String = "request/$id"
    }

    companion object {
        /** From an FCM data payload (`link` or `tx_id` extras). */
        fun fromData(data: Map<String, String>): DeepLink? {
            data["tx_id"]?.toLongOrNull()?.let { return ServiceRequest(it) }
            return fromPath(data["link"])
        }

        /** From a VIEW intent (App Link or custom scheme). */
        fun fromIntent(intent: Intent?): DeepLink? {
            val uri = intent?.data ?: return null
            return fromUri(uri)
        }

        private fun fromUri(uri: Uri): DeepLink? = when (uri.host) {
            "requests" -> uri.lastPathSegment?.toLongOrNull()?.let { ServiceRequest(it) }
            "onlinesheba.broxlab.online" -> fromPath(uri.path)
            else -> null
        }

        private fun fromPath(path: String?): DeepLink? {
            val match = Regex("^/?(?:index\\.php/)?requests/(\\d+)").find(path ?: "") ?: return null
            return match.groupValues[1].toLongOrNull()?.let { ServiceRequest(it) }
        }
    }
}
