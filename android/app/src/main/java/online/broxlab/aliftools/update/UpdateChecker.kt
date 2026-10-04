package online.broxlab.aliftools.update

import android.app.Activity
import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.Settings
import androidx.core.content.ContextCompat
import java.io.File
import java.util.Locale
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonObject
import okhttp3.OkHttpClient
import okhttp3.Request
import online.broxlab.aliftools.data.AppJson
import online.broxlab.aliftools.data.AppVersion
import online.broxlab.aliftools.data.decodeJson

/**
 * In-app updater for the sideloaded APK.
 *
 * ## Why this exists at all
 *
 * There is no Play Store listing — the APK comes from `/app/apk` on this
 * server. That makes "is there a newer build?" something the app has to answer
 * for itself, because nothing else will. `GET /api/app/version` is
 * unauthenticated for exactly that reason: an app whose token has expired must
 * still be able to discover the update that would fix it.
 *
 * ## Why the download goes through DownloadManager
 *
 * An APK is tens of megabytes. Over OkHttp inside the app that means holding a
 * socket for minutes, surviving process death, and re-implementing resume.
 * DownloadManager already does all three, and it shows the system download
 * notification — which matters here, because the very next step asks the user
 * to trust an unknown-source install. Something they did not start is much
 * easier to approve than something that appears from nowhere.
 *
 * ## Why there is a permission dance at all
 *
 * From API 26 the user can only install from sources they have explicitly
 * allowed, per app, in Settings. So the flow is: ask [canRequestInstall], send
 * them to that Settings screen if the answer is no, re-check on resume, and
 * only then download. Skipping the check produces a silent
 * REQUEST_INSTALL_PACKAGES failure, which is the most common way an updater
 * looks broken.
 */
object UpdateChecker {

    /** Subdirectory of the app's external files dir the APKs land in. */
    private const val APK_DIR = "updates"

    /**
     * Ask the backend what the newest published build is.
     *
     * Deliberately *not* the app's authenticated Retrofit service. That client
     * carries a refresh-on-401 interceptor, so a stale token would be rotated —
     * or, failing that, signed the user out — as a side effect of asking about
     * a build update. This is the one request that must be incapable of
     * changing auth state: an app too old to talk to the API is precisely the
     * one that needs the answer.
     *
     * Null on any failure — offline, 5xx, a malformed body. "No update found"
     * is the correct answer for a check that did not complete.
     */
    suspend fun check(baseUrl: String, versionCode: Int): AppVersion? = withContext(Dispatchers.IO) {
        val url = baseUrl.trimEnd('/') + "/api/app/version?version_code=$versionCode&platform=android"
        val request = Request.Builder()
            .url(url)
            .header("Accept", "application/json")
            .build()

        val body = runCatching {
            plainClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return@use null
                response.body?.string()
            }
        }.getOrNull() ?: return@withContext null

        val payload = runCatching { AppJson.json.parseToJsonElement(body) }.getOrNull() as? JsonObject
            ?: return@withContext null

        // The envelope's own `success` flag is deliberately not read. A failure
        // response carries no `data` member at all, so decoding the payload is
        // both one line shorter and a stricter test than the flag would be.
        decodeJson<AppVersion>(payload["data"])
    }

    private val plainClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(10, TimeUnit.SECONDS)
            .build()
    }

    /** Whether the OS will let this app open an install intent right now. */
    fun canRequestInstall(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return true
        }
        return context.packageManager.canRequestPackageInstalls()
    }

    /**
     * Send the user to the "install unknown apps" screen for this package.
     *
     * There is no result to capture — the setting is read fresh the next time
     * [canRequestInstall] is asked, which the caller does on resume.
     */
    fun requestInstallPermission(activity: Activity) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val intent = Intent(
            Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
            Uri.parse("package:" + activity.packageName),
        )
        // Some OEM builds ship without the screen; a user who cannot be sent
        // there should still be able to download the file by hand.
        runCatching { activity.startActivity(intent) }
    }

    /**
     * Download [url] and hand the bytes to the system installer.
     *
     * [onHandedOver] is true only when the install intent actually opened. The
     * user still has to press Install in that dialog, so this means "handed
     * over", not "updated" — the app may well never see the new version. It is
     * false on every path that never reaches the installer, including the ones
     * where nothing will ever call back, so the caller can always stop
     * showing a progress spinner.
     */
    fun downloadAndInstall(
        context: Context,
        url: String,
        fileName: String,
        onHandedOver: (Boolean) -> Unit,
    ) {
        val manager = context.getSystemService(Context.DOWNLOAD_SERVICE) as? DownloadManager
        if (manager == null) {
            onHandedOver(false)
            return
        }

        // DownloadManager creates the external files dir itself, but not a
        // nested "updates/" segment inside it, and enqueue() throws rather than
        // creating the missing parent.
        val dir = context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS)?.let { File(it, APK_DIR) }
        if (dir == null || (!dir.isDirectory && !dir.mkdirs())) {
            onHandedOver(false)
            return
        }

        val id = try {
            manager.enqueue(
                DownloadManager.Request(Uri.parse(url)).apply {
                    setTitle("All Seba আপডেট ডাউনলোড হচ্ছে")
                    setDescription("$fileName ইনস্টল করার জন্য প্রস্তুত হচ্ছে")
                    setNotificationVisibility(
                        DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED,
                    )
                    setAllowedOverRoaming(false)
                    // External files dir, not the public Downloads folder:
                    // writing to the latter needs WRITE_EXTERNAL_STORAGE before
                    // API 29 and is blocked outright by scoped storage from
                    // API 30. This needs no permission on any version, and the
                    // file is removed with the app.
                    setDestinationInExternalFilesDir(
                        context,
                        Environment.DIRECTORY_DOWNLOADS,
                        "$APK_DIR/$fileName",
                    )
                },
            )
        } catch (e: Exception) {
            onHandedOver(false)
            return
        }

        val receiver = object : BroadcastReceiver() {
            override fun onReceive(ctx: Context?, intent: Intent?) {
                if (intent?.action != DownloadManager.ACTION_DOWNLOAD_COMPLETE) return
                if (intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L) != id) return
                runCatching { ctx?.unregisterReceiver(this) }
                onHandedOver(openInstaller(ctx ?: context, manager, id))
            }
        }

        // Registered against the application context, not the activity: the
        // download can finish while the user is somewhere else in the app, and
        // an activity-scoped receiver would be unregistered long before that.
        //
        // RECEIVER_EXPORTED is deliberate rather than sloppy. On API 26-32 a
        // NOT_EXPORTED receiver is guarded by a synthetic same-app permission,
        // and the completion broadcast is sent by the download provider's own
        // process — so NOT_EXPORTED would silently never fire there. Targeting
        // 34 means the flag is mandatory here anyway. A spoofed broadcast can
        // do no worse than ask for the installer to open a file that was never
        // downloaded, because getUriForDownloadedFile() only resolves a real
        // completed download owned by this app.
        //
        // If registration itself fails there is nothing left that will ever
        // call back, so the download is cancelled and the caller is told.
        runCatching {
            ContextCompat.registerReceiver(
                context.applicationContext,
                receiver,
                IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE),
                ContextCompat.RECEIVER_EXPORTED,
            )
        }.onFailure {
            runCatching { manager.remove(id) }
            onHandedOver(false)
        }
    }

    /**
     * Open the system installer for a finished download.
     *
     * `getUriForDownloadedFile` hands back a `content://downloads/...` URI that
     * DownloadManager itself will grant read access to, which is why this needs
     * FLAG_GRANT_READ_URI_PERMISSION and no FileProvider of our own.
     */
    private fun openInstaller(
        context: Context,
        manager: DownloadManager,
        downloadId: Long,
    ): Boolean {
        val uri = manager.getUriForDownloadedFile(downloadId) ?: return false
        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        return runCatching { context.startActivity(intent) }.isSuccess
    }

    /** Human-friendly size for the dialog, e.g. "18.4 MB". */
    fun formatSize(bytes: Long): String {
        if (bytes <= 0L) return ""
        val mb = bytes / (1024.0 * 1024.0)
        return if (mb >= 1.0) {
            String.format(Locale.getDefault(), "%.1f MB", mb)
        } else {
            String.format(Locale.getDefault(), "%.0f KB", bytes / 1024.0)
        }
    }
}
