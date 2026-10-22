package com.aliftools.admin

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.util.Log
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.fragment.app.FragmentActivity
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.aliftools.admin.data.repository.AdminRepository
import com.aliftools.admin.data.local.SessionTimeoutHandler
import com.aliftools.admin.ui.navigation.Screen
import com.aliftools.admin.ui.theme.AlifAdminTheme
import com.aliftools.admin.ui.screens.dashboard.DashboardScreen
import com.aliftools.admin.ui.screens.logs.LogsScreen
import com.aliftools.admin.ui.screens.login.LoginScreen
import com.aliftools.admin.ui.screens.notifications.NotificationsScreen
import com.aliftools.admin.ui.screens.orders.OrdersScreen
import com.aliftools.admin.ui.screens.recharges.RechargesScreen
import com.aliftools.admin.ui.screens.settings.SettingsScreen
import com.aliftools.admin.ui.screens.splash.SplashScreen
import com.aliftools.admin.ui.screens.staff.StaffScreen
import com.aliftools.admin.ui.screens.users.UsersScreen
import com.aliftools.admin.ui.screens.withdraws.WithdrawsScreen
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.first
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : FragmentActivity() {

    /** Read once at startup to choose Login vs Dashboard (splash route). */
    @Inject
    lateinit var repository: AdminRepository

    /** Must be registered before the activity starts, hence a property. */
    private val notificationPermission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (!granted) Log.w(TAG, "POST_NOTIFICATIONS denied; push alerts will not display")
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        requestNotificationPermissionIfNeeded()
        setContent {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    val navController = rememberNavController()
                    // Where a tapped notification wants to go, if it said so.
                    var deepLink by remember { mutableStateOf(intentRoute(intent)) }

                    NavHost(navController = navController, startDestination = Screen.Splash.route) {
                        composable(Screen.Splash.route) {
                            var target by remember { mutableStateOf<String?>(null) }

                            SplashScreen()
                            // One effect owns every first navigation: the
                            // stored token decides Login vs Dashboard, and a
                            // notification's route (if any) rides on top of
                            // Dashboard. Splitting this across effects would
                            // race the splash against the deep link.
                            LaunchedEffect(Unit) {
                                val pending = deepLink
                                deepLink = null
                                val token = repository.accessTokenFlow().first()
                                val authed = !token.isNullOrBlank()
                                target = when {
                                    !authed -> Screen.Login.route
                                    else -> Screen.Dashboard.route
                                }
                                if (pending != null && authed) {
                                    // Remember where to go after Dashboard lands.
                                    target = Screen.Dashboard.route
                                    deepLink = pending
                                }
                            }
                            LaunchedEffect(target) {
                                target?.let { route ->
                                    navController.navigate(route) {
                                        popUpTo(Screen.Splash.route) { inclusive = true }
                                    }
                                    // Navigate again once the dashboard is the
                                    // current destination.
                                    val pending = deepLink
                                    if (pending != null) {
                                        deepLink = null
                                        navController.navigate(pending)
                                    }
                                }
                            }
                        }

                        composable(Screen.Login.route) {
                            LoginScreen(
                                onLoginSuccess = {
                                    navController.navigate(Screen.Dashboard.route) {
                                        popUpTo(Screen.Login.route) { inclusive = true }
                                    }
                                }
                            )
                        }

                        composable(Screen.Dashboard.route) {
                            DashboardScreen(
                                onNavigateToUsers = { navController.navigate(Screen.Users.route) },
                                onNavigateToOrders = { navController.navigate(Screen.Orders.route) },
                                onNavigateToRecharges = { navController.navigate(Screen.Recharges.route) },
                                onNavigateToWithdraws = { navController.navigate(Screen.Withdraws.route) },
                                onNavigateToSettings = { navController.navigate(Screen.Settings.route) },
                                onNavigateToLogs = { navController.navigate(Screen.Logs.route) },
                                onNavigateToNotifications = { navController.navigate(Screen.Notifications.route) },
                                onNavigateToStaff = { navController.navigate(Screen.Staff.route) },
                                onLoggedOut = {
                                    navController.navigate(Screen.Login.route) {
                                        popUpTo(Screen.Dashboard.route) { inclusive = true }
                                    }
                                }
                            )
                        }

                        composable(Screen.Users.route) {
                            UsersScreen(onBack = { navController.popBackStack() })
                        }
                        composable(Screen.Orders.route) {
                            OrdersScreen(onBack = { navController.popBackStack() })
                        }
                        composable(Screen.Recharges.route) {
                            RechargesScreen(onBack = { navController.popBackStack() })
                        }
                        composable(Screen.Withdraws.route) {
                            WithdrawsScreen(onBack = { navController.popBackStack() })
                        }
                        composable(Screen.Settings.route) {
                            SettingsScreen(onBack = { navController.popBackStack() })
                        }
                        composable(Screen.Logs.route) {
                            LogsScreen(onBack = { navController.popBackStack() })
                        }
                        composable(Screen.Staff.route) {
                            StaffScreen(onBack = { navController.popBackStack() })
                        }
                    }
                }
            }
        }
    }

    /** Android 13+ needs the runtime grant or notify() is silently dropped */
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

    override fun onResume() {
        super.onResume()
    }

    override fun onPause() {
        super.onPause()
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
        )

        private fun intentRoute(intent: Intent?): String? =
            intent?.getStringExtra(EXTRA_ROUTE)?.takeIf { it in NAVIGABLE }
    }
}