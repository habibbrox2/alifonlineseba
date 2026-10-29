package com.aliftools.admin.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import com.aliftools.admin.R

/**
 * Hind Siliguri is the web frontend's first family, bundled so Bengali renders
 * with the platform's letterforms offline and at predictable weights. The
 * system fallback (Noto Sans Bengali) covers glyphs outside the subset.
 */
val HindSiliguri = FontFamily(
    Font(R.font.hind_siliguri_regular, FontWeight.Normal),
    Font(R.font.hind_siliguri_medium, FontWeight.Medium),
    Font(R.font.hind_siliguri_semibold, FontWeight.SemiBold),
    Font(R.font.hind_siliguri_bold, FontWeight.Bold),
)

/**
 * Bengali has no case and no useful tracking: any letter-spacing separates a
 * consonant from its matra. So every role sets letterSpacing to zero and uses
 * line-heights tuned to the matra (1.45–1.5 body, ~1.3 headings).
 */
private fun bengali(
    size: Int,
    lineHeight: Int,
    weight: FontWeight,
) = TextStyle(
    fontFamily = HindSiliguri,
    fontWeight = weight,
    fontSize = size.sp,
    lineHeight = lineHeight.sp,
    letterSpacing = 0.sp,
)

val AlifTypography = Typography(
    displayLarge = bengali(36, 44, FontWeight.Bold),
    displayMedium = bengali(32, 40, FontWeight.Bold),
    displaySmall = bengali(30, 36, FontWeight.Bold),
    headlineLarge = bengali(28, 34, FontWeight.Bold),
    headlineMedium = bengali(26, 32, FontWeight.Bold),
    headlineSmall = bengali(22, 28, FontWeight.Bold),
    titleLarge = bengali(20, 26, FontWeight.SemiBold),
    titleMedium = bengali(17, 24, FontWeight.SemiBold),
    titleSmall = bengali(15, 20, FontWeight.Medium),
    bodyLarge = bengali(15, 22, FontWeight.Normal),
    bodyMedium = bengali(14, 21, FontWeight.Normal),
    bodySmall = bengali(13, 19, FontWeight.Normal),
    labelLarge = bengali(15, 20, FontWeight.SemiBold),
    labelMedium = bengali(12, 16, FontWeight.Medium),
    labelSmall = bengali(12, 16, FontWeight.Medium),
)

/** Money and IDs use tabular figures so columns of amounts line up. */
val MoneyStyle = bengali(17, 22, FontWeight.SemiBold).copy(
    fontFeatureSettings = "tnum",
)

val MoneyLargeStyle = bengali(28, 34, FontWeight.Bold).copy(
    fontFeatureSettings = "tnum",
)
