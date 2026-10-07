package com.aliftools.admin.ui.navigation

sealed class Screen(val route: String) {
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
}
