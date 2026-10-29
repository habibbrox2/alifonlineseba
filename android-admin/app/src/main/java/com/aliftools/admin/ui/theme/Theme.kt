package com.aliftools.admin.ui.theme

import android.app.Activity
import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

/**
 * Status and accent colours Material's scheme cannot express. Light/dark values
 * live in Color.kt so every component reads one source.
 */
val LocalAlifColors = staticCompositionLocalOf { LightAlifColors }

object AlifTheme {
    val colors: AlifColors
        @Composable get() = LocalAlifColors.current
}

/**
 * Brand-first theme.
 *
 * Dynamic colour is off by default: borrowing the wallpaper palette is why the
 * app read as unrelated to the platform. The parameter stays so a future
 * "use device colours" preference is a one-line opt-in.
 *
 * Status-bar icons are always light because every screen opens on the emerald
 * gradient bar; navigation-bar icons follow the theme.
 */
@Composable
fun AlifAdminTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = false,
    content: @Composable () -> Unit,
) {
    val context = LocalContext.current
    val colorScheme = when {
        dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ->
            if (darkTheme) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context)
        darkTheme -> DarkScheme
        else -> LightScheme
    }
    val alifColors = if (darkTheme) DarkAlifColors else LightAlifColors

    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as? Activity)?.window ?: return@SideEffect
            WindowCompat.getInsetsController(window, view).apply {
                // The top bar is always the emerald gradient.
                isAppearanceLightStatusBars = false
                isAppearanceLightNavigationBars = !darkTheme
            }
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.VANILLA_ICE_CREAM) {
                @Suppress("DEPRECATION")
                window.statusBarColor = Color.Transparent.toArgb()
                @Suppress("DEPRECATION")
                window.navigationBarColor = Color.Transparent.toArgb()
            }
        }
    }

    MaterialTheme(
        colorScheme = colorScheme,
        typography = AlifTypography,
        shapes = AlifShapes,
    ) {
        CompositionLocalProvider(LocalAlifColors provides alifColors, content = content)
    }
}
