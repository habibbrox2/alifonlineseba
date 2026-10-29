package com.aliftools.admin.ui.navigation

data class MenuSection(
    val group: AdminGroup,
    val items: List<AdminDestination>,
)

/**
 * Role gating for navigation.
 *
 * v1 derives this from the `role` string the login payload already stores,
 * mirroring the server's role checks. It is presentation only: the API still
 * returns 403 for anything this map gets wrong, and the UI must show the
 * permission state rather than assume access.
 *
 * Follow-up (documented in docs/ADMIN_ANDROID_UI_PLAN.md §3.2): add a
 * `permissions[]` payload so this can mirror the web sidebar exactly.
 */
object RoleCapabilities {

    private val superOnly = setOf(
        AdminDestinations.withdraws.route,
        AdminDestinations.settings.route,
        AdminDestinations.roleHierarchy.route,
        AdminDestinations.permissionsMatrix.route,
    )

    private val staffAllowed = setOf(
        AdminDestinations.orders.route,
        AdminDestinations.recharges.route,
        AdminDestinations.notifications.route,
    )

    fun isSuperAdmin(role: String?): Boolean =
        role?.contains("super", ignoreCase = true) == true

    fun isAdmin(role: String?): Boolean =
        isSuperAdmin(role) || role?.contains("admin", ignoreCase = true) == true

    fun canAccess(destination: AdminDestination, role: String?): Boolean {
        if (destination == AdminDestinations.home) return true
        if (role.isNullOrBlank()) return false
        if (isSuperAdmin(role)) return true
        if (isAdmin(role)) return destination.route !in superOnly
        return destination.route in staffAllowed
    }

    /**
     * Home first, then up to three queues in priority order, then "আরও".
     * The set is stable per role, so the bar never reshuffles per screen.
     */
    fun tabs(role: String?): List<AdminDestination> = buildList {
        add(AdminDestinations.home)
        AdminDestinations.queuePriority
            .filter { canAccess(it, role) }
            .take(3)
            .forEach(::add)
    }

    fun menu(role: String?): List<MenuSection> =
        AdminGroup.entries.mapNotNull { group ->
            val items = AdminDestinations.menuOrder
                .filter { it.group == group && canAccess(it, role) }
            if (items.isEmpty()) null else MenuSection(group, items)
        }
}
