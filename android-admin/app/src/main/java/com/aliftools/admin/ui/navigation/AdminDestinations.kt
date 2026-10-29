package com.aliftools.admin.ui.navigation

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ReceiptLong
import androidx.compose.material.icons.automirrored.outlined.Send
import androidx.compose.material.icons.outlined.AccountBalanceWallet
import androidx.compose.material.icons.outlined.AccountTree
import androidx.compose.material.icons.outlined.Badge
import androidx.compose.material.icons.outlined.CardGiftcard
import androidx.compose.material.icons.outlined.GridView
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.History
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Layers
import androidx.compose.material.icons.outlined.NotificationsActive
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.TableChart
import androidx.compose.ui.graphics.vector.ImageVector

/** Sidebar groups from the web, kept in the same order and wording. */
enum class AdminGroup(val label: String) {
    Management("পরিচালনা"),
    Finance("অর্থনীতি"),
    Settings("সেটিংস"),
    Monitoring("নিরীক্ষণ"),
}

data class AdminDestination(
    val route: String,
    val label: String,
    val icon: ImageVector,
    val group: AdminGroup? = null,
)

object AdminDestinations {
    val home = AdminDestination(Screen.Dashboard.route, "হোম", Icons.Outlined.Home)

    // Management
    val services = AdminDestination(Screen.Services.route, "সার্ভিস", Icons.Outlined.Layers, AdminGroup.Management)
    val categories = AdminDestination(Screen.Categories.route, "বিভাগ", Icons.Outlined.GridView, AdminGroup.Management)
    val staff = AdminDestination(Screen.Staff.route, "স্টাফ", Icons.Outlined.Badge, AdminGroup.Management)
    val users = AdminDestination(Screen.Users.route, "ইউজার", Icons.Outlined.Group, AdminGroup.Management)
    val orders = AdminDestination(Screen.Orders.route, "অর্ডার", Icons.Outlined.Inbox, AdminGroup.Management)
    val profile = AdminDestination(Screen.Profile.route, "আমার প্রোফাইল", Icons.Outlined.Person, AdminGroup.Management)

    // Finance
    val ledger = AdminDestination(Screen.Ledger.route, "আমার হিসাব", Icons.AutoMirrored.Outlined.ReceiptLong, AdminGroup.Finance)
    val recharges = AdminDestination(Screen.Recharges.route, "রিচার্জ", Icons.Outlined.AccountBalanceWallet, AdminGroup.Finance)
    val withdraws = AdminDestination(Screen.Withdraws.route, "উত্তোলন", Icons.AutoMirrored.Outlined.Send, AdminGroup.Finance)
    val referrals = AdminDestination(Screen.Referrals.route, "রেফারেল", Icons.Outlined.CardGiftcard, AdminGroup.Finance)

    // Settings
    val settings = AdminDestination(Screen.Settings.route, "সাইট সেটিংস", Icons.Outlined.Settings, AdminGroup.Settings)
    val roleHierarchy = AdminDestination(Screen.RoleHierarchy.route, "ভূমিকার পদক্ষেপ", Icons.Outlined.AccountTree, AdminGroup.Settings)
    val permissionsMatrix = AdminDestination(Screen.PermissionsMatrix.route, "অনুমতি ম্যাট্রিক্স", Icons.Outlined.TableChart, AdminGroup.Settings)

    // Monitoring
    val logs = AdminDestination(Screen.Logs.route, "অ্যাক্টিভিটি লগ", Icons.Outlined.History, AdminGroup.Monitoring)
    val notifications = AdminDestination(Screen.Notifications.route, "নোটিফিকেশন কিউ", Icons.Outlined.NotificationsActive, AdminGroup.Monitoring)

    /** Menu order mirrors partials/sidebar.twig. */
    val menuOrder = listOf(
        services, categories, staff, users, orders,
        ledger, recharges, withdraws, referrals,
        settings, roleHierarchy, permissionsMatrix,
        logs, notifications,
    )

    /** Queue priority for bottom-bar slots: money-out before lookups. */
    val queuePriority = listOf(orders, recharges, withdraws, users)
}
