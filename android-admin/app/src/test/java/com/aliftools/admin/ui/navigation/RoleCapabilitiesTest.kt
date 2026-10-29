package com.aliftools.admin.ui.navigation

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class RoleCapabilitiesTest {

    private fun routes(role: String?): List<String> =
        RoleCapabilities.tabs(role).map { it.route }

    @Test
    fun `staff gets orders and recharges as tab roots`() {
        assertEquals(
            listOf(
                AdminDestinations.home.route,
                AdminDestinations.orders.route,
                AdminDestinations.recharges.route,
            ),
            routes("staff"),
        )
    }

    @Test
    fun `admin adds users but not the money-out queue`() {
        assertEquals(
            listOf(
                AdminDestinations.home.route,
                AdminDestinations.orders.route,
                AdminDestinations.recharges.route,
                AdminDestinations.users.route,
            ),
            routes("admin"),
        )
        assertFalse(RoleCapabilities.canAccess(AdminDestinations.withdraws, "admin"))
    }

    @Test
    fun `superadmin swaps users for the withdraw queue`() {
        assertEquals(
            listOf(
                AdminDestinations.home.route,
                AdminDestinations.orders.route,
                AdminDestinations.recharges.route,
                AdminDestinations.withdraws.route,
            ),
            routes("superadmin"),
        )
    }

    @Test
    fun `unknown role sees only home until the role arrives`() {
        assertEquals(listOf(AdminDestinations.home.route), routes(null))
        assertFalse(RoleCapabilities.canAccess(AdminDestinations.orders, null))
    }

    @Test
    fun `superadmin sees every menu group but admin sees no settings section`() {
        val superGroups = RoleCapabilities.menu("superadmin").map { it.group }
        assertEquals(AdminGroup.entries, superGroups)

        val adminGroups = RoleCapabilities.menu("admin").map { it.group }
        assertFalse(AdminGroup.Settings in adminGroups)
        assertTrue(AdminGroup.Management in adminGroups)
    }

    @Test
    fun `staff menu keeps only permitted destinations`() {
        val staffRoutes = RoleCapabilities.menu("staff").flatMap { section ->
            section.items.map { it.route }
        }
        assertEquals(
            listOf(
                AdminDestinations.orders.route,
                AdminDestinations.recharges.route,
                AdminDestinations.notifications.route,
            ),
            staffRoutes,
        )
    }
}
