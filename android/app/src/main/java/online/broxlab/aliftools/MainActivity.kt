package online.broxlab.aliftools

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import androidx.navigation.navDeepLink
import online.broxlab.aliftools.data.AppContainer
import online.broxlab.aliftools.data.AppVersion
import online.broxlab.aliftools.push.DeepLink
import online.broxlab.aliftools.push.DeviceRegistrar
import online.broxlab.aliftools.ui.screens.DashboardScreen
import online.broxlab.aliftools.ui.screens.LoginScreen
import online.broxlab.aliftools.ui.screens.NotificationListScreen
import online.broxlab.aliftools.ui.screens.ServiceRequestDetailScreen
import online.broxlab.aliftools.update.UpdateChecker
import online.broxlab.aliftools.update.UpdateProgress
import online.broxlab.aliftools.update.UpdatePrompt

/**
 * Single-activity Compose app. Navigation mirrors the web UI: login →
 * dashboard → request detail, plus the notification list. Deep links from
 * pushes (audit §7) resolve into the same nav graph.
 */
class MainActivity : ComponentActivity() {

    /**
     * Android 13+ gates notifications behind a runtime permission; without it
     * FCM messages arrive but never render. Asked once on entry — a denial is
     * not fatal, the in-app notification list keeps working.
     */
    private val askNotificationPermission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { /* granted or not */ }

    private fun ensureNotificationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val granted = ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED
        if (!granted) {
            askNotificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        // Must run before super.onCreate(): it swaps the launch theme for
        // postSplashScreenTheme. Without it the branded splash never hands off
        // and the activity keeps the splash background on API 24-30.
        installSplashScreen()
        super.onCreate(savedInstanceState)
        val container = (application as AlifToolsApp).container
        val target = DeepLink.fromIntent(intent)
        ensureNotificationPermission()

        setContent {
            val session by container.session.isLoggedIn.collectAsState(initial = false)
            val nav = rememberNavController()

            // ---- In-app update check -------------------------------------
            // Asked once per process launch, not on every recomposition: this
            // is a network round trip and a dialog that reappears when the
            // user dismisses it would be a bug, not persistence.
            var pendingUpdate by remember { mutableStateOf<AppVersion?>(null) }
            var installing by remember { mutableStateOf(false) }
            var updateMessage by remember { mutableStateOf<String?>(null) }

            androidx.compose.runtime.LaunchedEffect(Unit) {
                val latest = UpdateChecker.check(BuildConfig.API_BASE_URL, BuildConfig.VERSION_CODE)
                if (latest != null && latest.updateAvailable) {
                    pendingUpdate = latest
                }
            }

            // The "install unknown apps" screen is a Settings page, so control
            // comes back here without a result. Re-reading the grant on resume
            // is the only way to know whether they said yes.
            //
            // Seeded from a real read rather than `false`: starting at false
            // would flash "সেটিংসে যান" at somebody who granted it a month ago.
            var canInstall by remember {
                mutableStateOf(UpdateChecker.canRequestInstall(this@MainActivity))
            }
            androidx.compose.runtime.DisposableEffect(Unit) {
                val watcher = object : android.content.BroadcastReceiver() {
                    override fun onReceive(ctx: android.content.Context?, intent: android.content.Intent?) {
                        canInstall = UpdateChecker.canRequestInstall(this@MainActivity)
                    }
                }
                runCatching {
                    // Context-registered, not manifest-registered: ACTION_ACTIVITY_RESUME
                    // is a protected broadcast, so a manifest receiver would be
                    // rejected on API 26+ but a runtime one receives it fine.
                    // RECEIVER_EXPORTED is required from API 34 and ignored before.
                    androidx.core.content.ContextCompat.registerReceiver(
                        this@MainActivity,
                        watcher,
                        android.content.IntentFilter(android.content.Intent.ACTION_ACTIVITY_RESUME),
                        androidx.core.content.ContextCompat.RECEIVER_EXPORTED,
                    )
                }
                onDispose {
                    runCatching { this@MainActivity.unregisterReceiver(watcher) }
                }
            }

            // A push tapped while logged in lands on the exact screen; cold
            // starts land on login first (the token gate is in the API layer).
            androidx.compose.runtime.LaunchedEffect(target, session) {
                if (target != null && session) {
                    nav.navigate(target.route()) { launchSingleTop = true }
                }
            }

            // Re-register the current FCM token on every logged-in start: the
            // token may have rotated while the process was dead, and a device
            // never registered is silent forever. No-op when Firebase is not
            // configured (no google-services.json in this build).
            androidx.compose.runtime.LaunchedEffect(session) {
                if (session) {
                    runCatching {
                        com.google.firebase.messaging.FirebaseMessaging
                            .getInstance().token
                            .addOnSuccessListener { token ->
                                DeviceRegistrar.enqueueRegistration(this@MainActivity, token)
                            }
                    }
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

            pendingUpdate?.let { version ->
                UpdatePrompt(
                    version = version,
                    canInstall = canInstall,
                    onDismiss = { pendingUpdate = null },
                    onInstall = {
                        val path = version.downloadUrl
                        when {
                            // No grant yet: go to Settings and let the resume
                            // watcher pick the answer up.
                            !canInstall -> UpdateChecker.requestInstallPermission(this@MainActivity)
                            // A published release with no path means the
                            // manifest and the filesystem disagree; the download
                            // link on /app would 404 the same way.
                            path == null -> updateMessage = "ডাউনলোড লিংক পাওয়া যায়নি। /app পেজ থেকে ডাউনলোড করুন।"
                            else -> {
                                // The manifest returns a site-relative path;
                                // resolving it against BuildConfig keeps the
                                // debug build pointed at the local server and
                                // release at production, with no second
                                // hard-coded host here.
                                val url = BuildConfig.API_BASE_URL.trimEnd('/') +
                                    "/" + path.trimStart('/')
                                pendingUpdate = null
                                installing = true
                                UpdateChecker.downloadAndInstall(
                                    context = this@MainActivity,
                                    url = url,
                                    fileName = "aliftools-${version.versionName}.apk",
                                ) { handedOver ->
                                    installing = false
                                    updateMessage = if (handedOver) {
                                        null
                                    } else {
                                        "ইনস্টল করা যায়নি। ফাইলটি ডাউনলোড ফোল্ডারে আছে — সেখান থেকে ইনস্টল করুন।"
                                    }
                                }
                            }
                        }
                    },
                )
            }

            UpdateProgress(
                working = installing,
                message = updateMessage,
                onDismiss = { updateMessage = null },
            )
        }
    }
}
