package online.broxlab.aliftools.push

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import kotlinx.coroutines.flow.first
import online.broxlab.aliftools.AlifToolsApp
import online.broxlab.aliftools.data.DeviceRegistration

/**
 * Registers/refreshes the FCM token with the backend. Queued as WorkManager
 * work so a token that arrives before login (or while offline) is retried
 * until POST /api/devices accepts it — the queue table dead-letters dormant
 * tokens server-side, but a device never registered is silent forever.
 */
object DeviceRegistrar {

    private const val UNIQUE_WORK = "fcm-token-registration"
    const val KEY_TOKEN = "token"

    fun enqueueRegistration(context: Context, token: String) {
        val request = OneTimeWorkRequestBuilder<TokenRegistrationWorker>()
            .setConstraints(
                Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build(),
            )
            .setInputData(workDataOf(KEY_TOKEN to token))
            .build()

        WorkManager.getInstance(context).enqueueUniqueWork(
            UNIQUE_WORK,
            ExistingWorkPolicy.REPLACE,
            request,
        )
    }
}

class TokenRegistrationWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext as? AlifToolsApp ?: return Result.failure()
        if (!app.container.session.isLoggedIn.first()) {
            // Not logged in yet — retry; login enqueues a fresh registration.
            return Result.retry()
        }

        val token = inputData.getString(DeviceRegistrar.KEY_TOKEN) ?: return Result.failure()
        val response = app.container.api.registerDevice(
            DeviceRegistration(
                token = token,
                device_name = android.os.Build.MODEL,
                app_version = appVersion(),
            ),
        )
        return if (response.success) Result.success() else Result.retry()
    }

    private fun appVersion(): String = try {
        val info = applicationContext.packageManager.getPackageInfo(applicationContext.packageName, 0)
        info.versionName ?: "0"
    } catch (_: Exception) {
        "0"
    }
}
