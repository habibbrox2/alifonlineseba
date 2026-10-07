package com.aliftools.admin.fcm

import android.util.Log
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.aliftools.admin.data.repository.AdminRepository
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

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)
        Log.d(TAG, "FCM message received: ${message.data}")

        val type = message.data["type"]
        val entityId = message.data["entity_id"]
        val route = message.data["route"]

        // TODO: Show notification with deep-link intent to relevant screen.
        // Use route from payload if available, otherwise default to app launcher.
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
