package com.aliftools.admin

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.util.Log
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.aliftools.admin.ui.AdminApp
import com.aliftools.admin.ui.navigation.Screen
import com.aliftools.admin.ui.theme.AlifAdminTheme
import dagger.hilt.android.AndroidEntryPoint

/**
 * Hosts the Compose shell. Session routing, tabs and logout live in
 * [AdminApp]; this activity only keeps platform concerns (edge-to-edge,
 * notification permission, notification deep links).
 */
@AndroidEntryPoint
class MainActivity : FragmentActivity() {

    /** Must be registered before the activity starts, hence a property. */
    private val notificationPermission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (!granted) Log.w(TAG, "POST_NOTIFICATIONS denied; push alerts will not display")
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // The emerald top bar draws behind the status bar; without this the bar
        // would sit below it on Android 14 and older.
        enableEdgeToEdge()
        requestNotificationPermissionIfNeeded()
        setContent {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    AdminApp(initialDeepLink = intentRoute(intent))
                }
            }
        }
    }

    /** Android 13+ needs the runtime grant or notify() is silently dropped. */
    private fun requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val granted = ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED
        if (!granted) {
            notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    companion object {
        private const val TAG = "AlifAdminMain"

        /** Extra carried by notification PendingIntents: a Screen.route value. */
        const val EXTRA_ROUTE = "route"

        /** Only these routes may arrive from outside; anything else is dropped. */
        private val NAVIGABLE = setOf(
            Screen.Users.route,
            Screen.Orders.route,
            Screen.Recharges.route,
            Screen.Withdraws.route,
            Screen.Settings.route,
            Screen.Logs.route,
            Screen.Notifications.route,
            Screen.Staff.route,
            Screen.Categories.route,
            Screen.Services.route,
            Screen.Ledger.route,
            Screen.Referrals.route,
            Screen.RoleHierarchy.route,
            Screen.PermissionsMatrix.route,
            Screen.Profile.route,
        )

        private fun intentRoute(intent: Intent?): String? =
            intent?.getStringExtra(EXTRA_ROUTE)?.takeIf { it in NAVIGABLE }
    }
}
