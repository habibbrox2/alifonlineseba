package com.aliftools.admin.ui.theme

import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Immutable
import androidx.compose.ui.graphics.Color

/*
 * Brand ramp taken from the web frontend (tailwind.config.js + app.css):
 * primary 900/800/700/600 and the gold accent. The roles are deliberately
 * reassigned for Android: brand-700 is the action fill because 15sp white on
 * brand-600 is only 4.1:1, while brand-700 is 6.2:1.
 */
val Brand900 = Color(0xFF083F31)
val Brand800 = Color(0xFF0A5540)
val Brand700 = Color(0xFF0B6E51)
val Brand600 = Color(0xFF0D8F68)
val Brand100 = Color(0xFFD1FAE5)
val Brand50 = Color(0xFFECFDF5)

val Gold500 = Color(0xFFF5B301)
val Gold200 = Color(0xFFFDE68A)
val Gold50 = Color(0xFFFFFBEB)
val GoldInk = Color(0xFF8A5F00)
val OnGold = Color(0xFF2B1D00)

val PageSurface = Color(0xFFF4F7F6)
val RaisedSurface = Color(0xFFFFFFFF)
val SunkenSurface = Color(0xFFE8EEEC)
val Hairline = Color(0xFFE5E7EB)

val InkPrimary = Color(0xFF1F2A26)
val InkSecondary = Color(0xFF525252)

/**
 * Status and brand colours that Material's [androidx.compose.material3.ColorScheme]
 * does not carry. Provided through [LocalAlifColors] so dark mode is one table,
 * not a second copy of every component rule.
 */
@Immutable
data class AlifColors(
    val heroStart: Color,
    val heroMid: Color,
    val heroEnd: Color,
    val gold: Color,
    val goldContainer: Color,
    val goldInk: Color,
    val onGold: Color,
    val success: Color,
    val successContainer: Color,
    val successInk: Color,
    val warning: Color,
    val warningContainer: Color,
    val warningInk: Color,
    val info: Color,
    val infoContainer: Color,
    val infoInk: Color,
    val danger: Color,
    val dangerContainer: Color,
    val dangerInk: Color,
    val neutral: Color,
    val neutralContainer: Color,
    val neutralInk: Color,
)

internal val LightAlifColors = AlifColors(
    heroStart = Brand900,
    heroMid = Brand800,
    heroEnd = Brand700,
    gold = Gold500,
    goldContainer = Gold50,
    goldInk = GoldInk,
    onGold = OnGold,
    success = Color(0xFF059669),
    successContainer = Color(0xFFECFDF5),
    successInk = Color(0xFF047857),
    warning = Color(0xFFD97706),
    warningContainer = Color(0xFFFFFBEB),
    warningInk = Color(0xFFB45309),
    info = Color(0xFF2563EB),
    infoContainer = Color(0xFFEFF6FF),
    infoInk = Color(0xFF1D4ED8),
    danger = Color(0xFFDC2626),
    dangerContainer = Color(0xFFFEF2F2),
    dangerInk = Color(0xFFB91C1C),
    neutral = Color(0xFF525252),
    neutralContainer = Color(0xFFF5F5F5),
    neutralInk = Color(0xFF525252),
)

internal val DarkAlifColors = AlifColors(
    heroStart = Color(0xFF04231C),
    heroMid = Color(0xFF072F24),
    heroEnd = Color(0xFF0A3D2F),
    gold = Color(0xFFFBBF24),
    goldContainer = Color(0xFF3A2405),
    goldInk = Color(0xFFFCD34D),
    onGold = Color(0xFF2A1D00),
    success = Color(0xFF34D399),
    successContainer = Color(0xFF062A20),
    successInk = Color(0xFF6EE7B7),
    warning = Color(0xFFFBBF24),
    warningContainer = Color(0xFF3A2405),
    warningInk = Color(0xFFFCD34D),
    info = Color(0xFF60A5FA),
    infoContainer = Color(0xFF0B2440),
    infoInk = Color(0xFF93C5FD),
    danger = Color(0xFFF87171),
    dangerContainer = Color(0xFF3A0D0D),
    dangerInk = Color(0xFFFCA5A5),
    neutral = Color(0xFF94A3B8),
    neutralContainer = Color(0xFF1C2A25),
    neutralInk = Color(0xFFB8C6C0),
)

internal val LightScheme = lightColorScheme(
    primary = Brand700,
    onPrimary = Color.White,
    primaryContainer = Brand100,
    onPrimaryContainer = Brand900,
    secondary = Color(0xFF4A6365),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFCCE8E9),
    onSecondaryContainer = Color(0xFF051F20),
    tertiary = GoldInk,
    onTertiary = Color.White,
    tertiaryContainer = Gold50,
    onTertiaryContainer = OnGold,
    background = PageSurface,
    onBackground = InkPrimary,
    surface = RaisedSurface,
    onSurface = InkPrimary,
    surfaceVariant = SunkenSurface,
    onSurfaceVariant = InkSecondary,
    outline = Color(0xFFCBD5D0),
    outlineVariant = Hairline,
    error = Color(0xFFDC2626),
    onError = Color.White,
    errorContainer = Color(0xFFFEF2F2),
    onErrorContainer = Color(0xFFB91C1C),
    scrim = Color(0xFF083F31),
)

internal val DarkScheme = darkColorScheme(
    primary = Color(0xFF34D399),
    onPrimary = Color(0xFF04231A),
    primaryContainer = Color(0xFF0B2C22),
    onPrimaryContainer = Color(0xFF6EE7B7),
    secondary = Color(0xFFA9BAB4),
    onSecondary = Color(0xFF10201C),
    secondaryContainer = Color(0xFF1C3536),
    onSecondaryContainer = Color(0xFFCCE8E9),
    tertiary = Color(0xFFFCD34D),
    onTertiary = Color(0xFF2A1D00),
    tertiaryContainer = Color(0xFF3A2405),
    onTertiaryContainer = Color(0xFFFCD34D),
    background = Color(0xFF08120F),
    onBackground = Color(0xFFE6EFEB),
    surface = Color(0xFF0E1E19),
    onSurface = Color(0xFFE6EFEB),
    surfaceVariant = Color(0xFF16302A),
    onSurfaceVariant = Color(0xFFA9BAB4),
    outline = Color(0xFF24443B),
    outlineVariant = Color(0xFF1B3A31),
    error = Color(0xFFF87171),
    onError = Color(0xFF450A0A),
    errorContainer = Color(0xFF3A0D0D),
    onErrorContainer = Color(0xFFFCA5A5),
    scrim = Color(0xFF04120E),
)
