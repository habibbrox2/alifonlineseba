package com.aliftools.admin.screenshots

import app.cash.paparazzi.DeviceConfig
import app.cash.paparazzi.Paparazzi
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.aliftools.admin.ui.theme.AlifAdminTheme
import org.junit.Rule
import org.junit.Test

class AllScreensScreenshotTest {
    @get:Rule
    val paparazzi = Paparazzi(
        deviceConfig = DeviceConfig.PIXEL_5,
    )

    @Test
    fun dashboardScreen() {
        paparazzi.snapshot(name = "DashboardScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockDashboardScreen()
                }
            }
        }
    }

    @Test
    fun usersScreen() {
        paparazzi.snapshot(name = "UsersScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockUsersScreen()
                }
            }
        }
    }

    @Test
    fun ordersScreen() {
        paparazzi.snapshot(name = "OrdersScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "অর্ডারসমূহ")
                }
            }
        }
    }

    @Test
    fun rechargesScreen() {
        paparazzi.snapshot(name = "RechargesScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "রিচার্জসমূহ")
                }
            }
        }
    }

    @Test
    fun withdrawsScreen() {
        paparazzi.snapshot(name = "WithdrawsScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "উইথড্র সমূহ")
                }
            }
        }
    }

    @Test
    fun servicesScreen() {
        paparazzi.snapshot(name = "ServicesScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "সার্ভিসসমূহ")
                }
            }
        }
    }

    @Test
    fun categoriesScreen() {
        paparazzi.snapshot(name = "CategoriesScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "ক্যাটেগরিসমূহ")
                }
            }
        }
    }

    @Test
    fun ledgerScreen() {
        paparazzi.snapshot(name = "LedgerScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "লেজার")
                }
            }
        }
    }

    @Test
    fun staffScreen() {
        paparazzi.snapshot(name = "StaffScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "স্টাফ ব্যবস্থাপনা")
                }
            }
        }
    }

    @Test
    fun permissionsMatrixScreen() {
        paparazzi.snapshot(name = "PermissionsMatrixScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "অনুমতি ম্যাট্রিক্স")
                }
            }
        }
    }

    @Test
    fun roleHierarchyScreen() {
        paparazzi.snapshot(name = "RoleHierarchyScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "রোল হায়ারার্কি")
                }
            }
        }
    }

    @Test
    fun referralsScreen() {
        paparazzi.snapshot(name = "ReferralsScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "রেফারেলসমূহ")
                }
            }
        }
    }

    @Test
    fun notificationsScreen() {
        paparazzi.snapshot(name = "NotificationsScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "নোটিফিকেশন")
                }
            }
        }
    }

    @Test
    fun logsScreen() {
        paparazzi.snapshot(name = "LogsScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "অ্যাকটিভিটি লগ")
                }
            }
        }
    }

    @Test
    fun settingsScreen() {
        paparazzi.snapshot(name = "SettingsScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockListScreen(title = "সেটিংস")
                }
            }
        }
    }

    @Test
    fun profileScreen() {
        paparazzi.snapshot(name = "ProfileScreen") {
            AlifAdminTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    MockProfileScreen()
                }
            }
        }
    }
}
