package com.aliftools.admin.util

import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.widget.Toast
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import com.aliftools.admin.BuildConfig
import java.io.File

object AppUpdateHelper {

    fun resolveDownloadUrl(downloadUrl: String?): String {
        if (downloadUrl.isNullOrBlank()) {
            return BuildConfig.BASE_URL.trimEnd('/') + "/app/apk?channel=admin"
        }
        return if (downloadUrl.startsWith("http://") || downloadUrl.startsWith("https://")) {
            downloadUrl
        } else {
            val base = BuildConfig.BASE_URL.trimEnd('/')
            val path = if (downloadUrl.startsWith("/")) downloadUrl else "/$downloadUrl"
            "$base$path"
        }
    }

    fun downloadAndInstall(
        context: Context,
        downloadUrl: String?,
        versionName: String,
        onStarted: () -> Unit = {},
        onError: (String) -> Unit = {},
    ) {
        val fullUrl = resolveDownloadUrl(downloadUrl)
        try {
            val downloadManager = context.getSystemService(Context.DOWNLOAD_SERVICE) as? DownloadManager
            if (downloadManager == null) {
                openInBrowser(context, fullUrl)
                return
            }

            val fileName = "alif-admin-v$versionName.apk"
            val downloadsDir = context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS)
                ?: context.filesDir
            val destinationFile = File(downloadsDir, fileName)

            if (destinationFile.exists()) {
                destinationFile.delete()
            }

            val request = DownloadManager.Request(Uri.parse(fullUrl)).apply {
                setTitle("Alif Admin v$versionName")
                setDescription("নতুন সংস্করণ ডাউনলোড হচ্ছে…")
                setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                setMimeType("application/vnd.android.package-archive")
                setDestinationUri(Uri.fromFile(destinationFile))
                setAllowedOverMetered(true)
                setAllowedOverRoaming(true)
            }

            val downloadId = downloadManager.enqueue(request)
            onStarted()
            Toast.makeText(context, "ডাউনলোড শুরু হয়েছে… সম্পন্ন হলে ইনস্টলেশন উইন্ডো খুলবে", Toast.LENGTH_LONG).show()

            val receiver = object : BroadcastReceiver() {
                override fun onReceive(c: Context, intent: Intent) {
                    val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1)
                    if (id == downloadId) {
                        try {
                            c.unregisterReceiver(this)
                        } catch (_: Exception) {}
                        installApk(c, destinationFile)
                    }
                }
            }

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                context.registerReceiver(
                    receiver,
                    IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE),
                    Context.RECEIVER_NOT_EXPORTED,
                )
            } else {
                context.registerReceiver(
                    receiver,
                    IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE),
                )
            }
        } catch (e: Exception) {
            onError(e.message ?: "ডাউনলোড শুরু করা যায়নি")
            openInBrowser(context, fullUrl)
        }
    }

    fun installApk(context: Context, file: File) {
        try {
            if (!file.exists()) {
                Toast.makeText(context, "ডাউনলোড ফাইলটি পাওয়া যায়নি", Toast.LENGTH_SHORT).show()
                return
            }
            val apkUri: Uri = FileProvider.getUriForFile(
                context,
                "${context.packageName}.fileprovider",
                file,
            )
            val installIntent = Intent(Intent.ACTION_VIEW).apply {
                setDataAndType(apkUri, "application/vnd.android.package-archive")
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            context.startActivity(installIntent)
        } catch (e: Exception) {
            Toast.makeText(context, "ইনস্টলার খুলতে ব্যর্থ: ${e.message}", Toast.LENGTH_LONG).show()
        }
    }

    fun openInBrowser(context: Context, url: String) {
        try {
            val fullUrl = resolveDownloadUrl(url)
            val intent = Intent(Intent.ACTION_VIEW, Uri.parse(fullUrl)).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            }
            context.startActivity(intent)
        } catch (e: Exception) {
            Toast.makeText(context, "ব্রাউজার খোলা যায়নি: ${e.message}", Toast.LENGTH_LONG).show()
        }
    }
}

