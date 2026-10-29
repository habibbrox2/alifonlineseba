package com.aliftools.admin.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.ui.unit.dp

/** Radii mirror the web: 12/14px cards, 8px controls, full pills. */
val AlifShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp),
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(14.dp),
    large = RoundedCornerShape(18.dp),
    extraLarge = RoundedCornerShape(20.dp),
)

object Dimens {
    val Gutter = 16.dp
    val GuttersWide = 24.dp
    val CardPadding = 16.dp
    val SectionGap = 24.dp
    val RowGap = 8.dp
    val TouchTarget = 48.dp
    val TopBarHeight = 54.dp
    val BottomBarHeight = 60.dp
    val IconTileSmall = 26.dp
    val IconTile = 32.dp
    val StatTileIcon = 32.dp
    val SheetCorner = 24.dp
}
