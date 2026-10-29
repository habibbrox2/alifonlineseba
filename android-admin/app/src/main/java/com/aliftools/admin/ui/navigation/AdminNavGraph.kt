package com.aliftools.admin.ui.navigation

sealed class Screen(val route: String) {
    /** Decides between Login and Dashboard by reading the stored token. */
    data object Splash : Screen("splash")
    data object Login : Screen("login")
    data object Dashboard : Screen("dashboard")
    data object Users : Screen("users")
    data object Orders : Screen("orders")
    data object Recharges : Screen("recharges")
    data object Withdraws : Screen("withdraws")
    data object Settings : Screen("settings")
    data object Logs : Screen("logs")
    data object Notifications : Screen("notifications")
    data object Staff : Screen("staff")
    data object Categories : Screen("categories")
    data object Services : Screen("services")
    data object Ledger : Screen("ledger")
    data object Referrals : Screen("referrals")
    data object RoleHierarchy : Screen("role_hierarchy")
    data object PermissionsMatrix : Screen("permissions_matrix")
    data object Profile : Screen("profile")
}
