package com.aliftools.admin.ui.components

import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.staticCompositionLocalOf
import com.aliftools.admin.ui.components.design.AdminTopBar

/**
 * True while the tab bar is on screen. Root screens then skip their own bottom
 * inset, because the shell already reserved the bar's height.
 */
val LocalAdminBottomBarVisible = staticCompositionLocalOf { false }

/**
 * Shell for every screen after the splash/login: the emerald bar with a title,
 * a way back (only when there is somewhere to go), actions, and a snackbar host
 * fed by one-shot ViewModel messages.
 */
@Composable
fun AdminScaffold(
    title: String,
    onBack: () -> Unit,
    actions: @Composable RowScope.() -> Unit = {},
    snack: String? = null,
    onSnackShown: () -> Unit = {},
    /** False on tab roots, where the bottom bar is the navigation. */
    showBack: Boolean = true,
    /** Replaces the standard bar entirely (Home draws its own extended hero). */
    topBar: (@Composable () -> Unit)? = null,
    content: @Composable (PaddingValues) -> Unit,
) {
    val snackbarHostState = remember { SnackbarHostState() }
    val bottomBarVisible = LocalAdminBottomBarVisible.current

    LaunchedEffect(snack) {
        if (snack != null) {
            snackbarHostState.showSnackbar(snack)
            onSnackShown()
        }
    }

    Scaffold(
        topBar = {
            if (topBar != null) {
                topBar()
            } else {
                AdminTopBar(
                    title = title,
                    onBack = if (showBack) onBack else null,
                    actions = actions,
                )
            }
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
        // The top bar owns the status-bar inset. The bottom inset belongs to
        // the shell when the tab bar is up; otherwise the screen keeps it.
        contentWindowInsets = if (bottomBarVisible) {
            WindowInsets.safeDrawing.only(WindowInsetsSides.Horizontal)
        } else {
            WindowInsets.safeDrawing.only(
                WindowInsetsSides.Horizontal + WindowInsetsSides.Bottom,
            )
        },
        content = content,
    )
}
