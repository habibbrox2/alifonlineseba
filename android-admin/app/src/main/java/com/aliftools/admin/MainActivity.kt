package com.aliftools.admin

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.aliftools.admin.ui.navigation.Screen
import com.aliftools.admin.ui.screens.dashboard.DashboardScreen
import com.aliftools.admin.ui.screens.logs.LogsScreen
import com.aliftools.admin.ui.screens.login.LoginScreen
import com.aliftools.admin.ui.screens.notifications.NotificationsScreen
import com.aliftools.admin.ui.screens.orders.OrdersScreen
import com.aliftools.admin.ui.screens.recharges.RechargesScreen
import com.aliftools.admin.ui.screens.settings.SettingsScreen
import com.aliftools.admin.ui.screens.staff.StaffScreen
import com.aliftools.admin.ui.screens.users.UsersScreen
import com.aliftools.admin.ui.screens.withdraws.WithdrawsScreen
import com.aliftools.admin.ui.viewmodel.LoginViewModel
import dagger.hilt.android.AndroidEntryPoint

@AndroidEntryPoint
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme {
                Surface(modifier = androidx.compose.ui.Modifier.fillMaxSize()) {
                    val navController = rememberNavController()
                    val loginViewModel: LoginViewModel = hiltViewModel()
                    val loginState by loginViewModel.state.collectAsState()

                    NavHost(navController = navController, startDestination = Screen.Login.route) {
                        composable(Screen.Login.route) {
                            LoginScreen(
                                onLoginSuccess = { role ->
                                    navController.navigate(Screen.Dashboard.route) {
                                        popUpTo(Screen.Login.route) { inclusive = true }
                                    }
                                }
                            )
                        }
                        composable(Screen.Dashboard.route) { DashboardScreen(
                            onNavigateToUsers = { navController.navigate(Screen.Users.route) },
                            onNavigateToOrders = { navController.navigate(Screen.Orders.route) },
                            onNavigateToRecharges = { navController.navigate(Screen.Recharges.route) },
                            onNavigateToWithdraws = { navController.navigate(Screen.Withdraws.route) },
                            onNavigateToSettings = { navController.navigate(Screen.Settings.route) },
                            onNavigateToLogs = { navController.navigate(Screen.Logs.route) },
                            onNavigateToNotifications = { navController.navigate(Screen.Notifications.route) },
                            onNavigateToStaff = { navController.navigate(Screen.Staff.route) }
                        ) }
                        composable(Screen.Users.route) { UsersScreen() }
                        composable(Screen.Orders.route) { OrdersScreen() }
                        composable(Screen.Recharges.route) { RechargesScreen() }
                        composable(Screen.Withdraws.route) { WithdrawsScreen() }
                        composable(Screen.Settings.route) { SettingsScreen() }
                        composable(Screen.Logs.route) { LogsScreen() }
                        composable(Screen.Notifications.route) { NotificationsScreen() }
                        composable(Screen.Staff.route) { StaffScreen() }
                    }
                }
            }
        }
    }
}
