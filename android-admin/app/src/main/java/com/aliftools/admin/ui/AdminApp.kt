package com.aliftools.admin.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.aliftools.admin.ui.components.AdminMoreSheet
import com.aliftools.admin.ui.components.LocalAdminBottomBarVisible
import com.aliftools.admin.ui.components.design.AdminBottomBar
import com.aliftools.admin.ui.components.design.AdminTab
import com.aliftools.admin.ui.components.design.moreTab
import com.aliftools.admin.ui.navigation.RoleCapabilities
import com.aliftools.admin.ui.navigation.Screen
import com.aliftools.admin.ui.screens.categories.CategoriesScreen
import com.aliftools.admin.ui.screens.dashboard.DashboardScreen
import com.aliftools.admin.ui.screens.ledger.LedgerScreen
import com.aliftools.admin.ui.screens.login.LoginScreen
import com.aliftools.admin.ui.screens.logs.LogsScreen
import com.aliftools.admin.ui.screens.notifications.NotificationsScreen
import com.aliftools.admin.ui.screens.orders.OrdersScreen
import com.aliftools.admin.ui.screens.permissions.PermissionsMatrixScreen
import com.aliftools.admin.ui.screens.recharges.RechargesScreen
import com.aliftools.admin.ui.screens.referrals.ReferralsScreen
import com.aliftools.admin.ui.screens.rolehierarchy.RoleHierarchyScreen
import com.aliftools.admin.ui.screens.services.ServicesScreen
import com.aliftools.admin.ui.screens.settings.SettingsScreen
import com.aliftools.admin.ui.screens.splash.SplashScreen
import com.aliftools.admin.ui.screens.staff.StaffScreen
import com.aliftools.admin.ui.screens.users.UsersScreen
import com.aliftools.admin.ui.screens.withdraws.WithdrawsScreen
import com.aliftools.admin.ui.theme.Dimens
import com.aliftools.admin.ui.viewmodel.AdminShellViewModel
import androidx.compose.ui.unit.dp

/**
 * App shell: splash → login → tabbed console.
 *
 * The bottom bar is the only navigation for root queues; everything else is
 * reached through "আরও", which mirrors the web sidebar's permission-filtered
 * groups. Pushed screens keep their back arrow, tab roots do not.
 */
@Composable
fun AdminApp(
    initialDeepLink: String? = null,
    viewModel: AdminShellViewModel = hiltViewModel(),
) {
    val authed by viewModel.authed.collectAsState()
    val role by viewModel.role.collectAsState()
    val loggedOut by viewModel.loggedOut.collectAsState()

    val navController = rememberNavController()
    var pendingDeepLink by remember { mutableStateOf(initialDeepLink) }
    var showMore by remember { mutableStateOf(false) }

    val tabs = remember(role) {
        RoleCapabilities.tabs(role).map { AdminTab(it.route, it.label, it.icon) } + moreTab()
    }
    val tabRoutes = remember(tabs) { tabs.mapNotNull { it.route }.toSet() }

    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route
    val barVisible = currentRoute != null && currentRoute in tabRoutes

    LaunchedEffect(loggedOut) {
        if (loggedOut) {
            showMore = false
            navController.navigate(Screen.Login.route) {
                popUpTo(navController.graph.id) { inclusive = true }
            }
            viewModel.consumeLoggedOut()
        }
    }

    val bottomBarSpace = Dimens.BottomBarHeight +
        WindowInsets.navigationBars.asPaddingValues().calculateBottomPadding()

    fun openTab(route: String) {
        showMore = false
        navController.navigate(route) {
            popUpTo(Screen.Dashboard.route) { saveState = true }
            launchSingleTop = true
            restoreState = true
        }
    }

    Box(modifier = Modifier.fillMaxSize()) {
        CompositionLocalProvider(LocalAdminBottomBarVisible provides barVisible) {
            NavHost(
                navController = navController,
                startDestination = Screen.Splash.route,
                modifier = Modifier
                    .fillMaxSize()
                    .padding(bottom = if (barVisible) bottomBarSpace else 0.dp),
            ) {
                composable(Screen.Splash.route) {
                    SplashScreen()
                    LaunchedEffect(authed) {
                        val signedIn = authed ?: return@LaunchedEffect
                        val destination = if (signedIn) Screen.Dashboard else Screen.Login
                        navController.navigate(destination.route) {
                            popUpTo(Screen.Splash.route) { inclusive = true }
                        }
                        val deep = pendingDeepLink
                        pendingDeepLink = null
                        if (signedIn && deep != null) {
                            navController.navigate(deep)
                        }
                    }
                }

                composable(Screen.Login.route) {
                    LoginScreen(
                        onLoginSuccess = { _ ->
                            navController.navigate(Screen.Dashboard.route) {
                                popUpTo(Screen.Login.route) { inclusive = true }
                            }
                        },
                    )
                }

                composable(Screen.Dashboard.route) {
                    DashboardScreen(
                        onNavigateToUsers = { openTab(Screen.Users.route) },
                        onNavigateToOrders = { openTab(Screen.Orders.route) },
                        onNavigateToRecharges = { openTab(Screen.Recharges.route) },
                        onNavigateToWithdraws = { openTab(Screen.Withdraws.route) },
                        onNavigateToSettings = { navController.navigate(Screen.Settings.route) },
                        onNavigateToLogs = { navController.navigate(Screen.Logs.route) },
                        onNavigateToNotifications = { navController.navigate(Screen.Notifications.route) },
                        onNavigateToStaff = { navController.navigate(Screen.Staff.route) },
                        onNavigateToCategories = { navController.navigate(Screen.Categories.route) },
                        onNavigateToServices = { navController.navigate(Screen.Services.route) },
                        onNavigateToLedger = { navController.navigate(Screen.Ledger.route) },
                        onNavigateToReferrals = { navController.navigate(Screen.Referrals.route) },
                        onNavigateToRoleHierarchy = { navController.navigate(Screen.RoleHierarchy.route) },
                        onNavigateToPermissionsMatrix = { navController.navigate(Screen.PermissionsMatrix.route) },
                        onLoggedOut = {
                            navController.navigate(Screen.Login.route) {
                                popUpTo(navController.graph.id) { inclusive = true }
                            }
                        },
                    )
                }

                composable(Screen.Users.route) {
                    UsersScreen(
                        onBack = { navController.popBackStack() },
                        showBack = Screen.Users.route !in tabRoutes,
                    )
                }
                composable(Screen.Orders.route) {
                    OrdersScreen(
                        onBack = { navController.popBackStack() },
                        showBack = Screen.Orders.route !in tabRoutes,
                    )
                }
                composable(Screen.Recharges.route) {
                    RechargesScreen(
                        onBack = { navController.popBackStack() },
                        showBack = Screen.Recharges.route !in tabRoutes,
                    )
                }
                composable(Screen.Withdraws.route) {
                    WithdrawsScreen(
                        onBack = { navController.popBackStack() },
                        showBack = Screen.Withdraws.route !in tabRoutes,
                    )
                }

                composable(Screen.Settings.route) {
                    SettingsScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.Logs.route) {
                    LogsScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.Notifications.route) {
                    NotificationsScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.Staff.route) {
                    StaffScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.Categories.route) {
                    CategoriesScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.Services.route) {
                    ServicesScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.Ledger.route) {
                    LedgerScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.Referrals.route) {
                    ReferralsScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.RoleHierarchy.route) {
                    RoleHierarchyScreen(onBack = { navController.popBackStack() })
                }
                composable(Screen.PermissionsMatrix.route) {
                    PermissionsMatrixScreen(onBack = { navController.popBackStack() })
                }
            }
        }

        if (barVisible) {
            AdminBottomBar(
                tabs = tabs,
                selectedRoute = currentRoute,
                moreSelected = showMore,
                onSelect = { tab ->
                    val route = tab.route
                    if (route == null) {
                        showMore = true
                    } else {
                        openTab(route)
                    }
                },
                modifier = Modifier.align(Alignment.BottomCenter),
            )
        }
    }

    if (showMore) {
        AdminMoreSheet(
            role = role,
            onDismiss = { showMore = false },
            onNavigate = { route ->
                if (route in tabRoutes) {
                    openTab(route)
                } else {
                    showMore = false
                    navController.navigate(route) { launchSingleTop = true }
                }
            },
            onLogout = viewModel::logout,
        )
    }
}

