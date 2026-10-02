package online.broxlab.aliftools

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import androidx.navigation.navDeepLink
import online.broxlab.aliftools.data.AppContainer
import online.broxlab.aliftools.push.DeepLink
import online.broxlab.aliftools.ui.screens.DashboardScreen
import online.broxlab.aliftools.ui.screens.LoginScreen
import online.broxlab.aliftools.ui.screens.NotificationListScreen
import online.broxlab.aliftools.ui.screens.ServiceRequestDetailScreen

/**
 * Single-activity Compose app. Navigation mirrors the web UI: login →
 * dashboard → request detail, plus the notification list. Deep links from
 * pushes (audit §7) resolve into the same nav graph.
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        // Must run before super.onCreate(): it swaps the launch theme for
        // postSplashScreenTheme. Without it the branded splash never hands off
        // and the activity keeps the splash background on API 24-30.
        installSplashScreen()
        super.onCreate(savedInstanceState)
        val container = (application as AlifToolsApp).container
        val target = DeepLink.fromIntent(intent)

        setContent {
            val session by container.session.isLoggedIn.collectAsState(initial = false)
            val nav = rememberNavController()

            // A push tapped while logged in lands on the exact screen; cold
            // starts land on login first (the token gate is in the API layer).
            androidx.compose.runtime.LaunchedEffect(target, session) {
                if (target != null && session) {
                    nav.navigate(target.route()) { launchSingleTop = true }
                }
            }

            NavHost(navController = nav, startDestination = if (session) "dashboard" else "login") {
                composable("login") {
                    LoginScreen(
                        onLoggedIn = { nav.navigate("dashboard") { popUpTo("login") { inclusive = true } } },
                        api = container.api,
                        session = container.session,
                    )
                }
                composable("dashboard") {
                    DashboardScreen(
                        session = container.session,
                        api = container.api,
                        onOpenRequest = { id -> nav.navigate("request/$id") },
                        onOpenNotifications = { nav.navigate("notifications") },
                        onLogout = {
                            container.session.clear()
                            nav.navigate("login") { popUpTo(0) }
                        },
                    )
                }
                composable(
                    route = "request/{id}",
                    arguments = listOf(navArgument("id") { type = NavType.LongType }),
                    deepLinks = listOf(
                        navDeepLink { uriPattern = "aliftools://requests/{id}" },
                        navDeepLink { uriPattern = "https://onlinesheba.broxlab.online/requests/{id}" },
                    ),
                ) { entry ->
                    ServiceRequestDetailScreen(
                        requestId = entry.arguments?.getLong("id") ?: 0L,
                        api = container.api,
                        session = container.session,
                    )
                }
                composable("notifications") {
                    NotificationListScreen(api = container.api, session = container.session)
                }
            }
        }
    }
}
