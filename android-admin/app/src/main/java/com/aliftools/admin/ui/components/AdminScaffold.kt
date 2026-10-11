package com.aliftools.admin.ui.components

import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.RowScope
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember

/**
 * The shell every screen after the dashboard shares: a top bar with a title,
 * a way back (the nav graph starts each detail screen with no toolbar of its
 * own, so this is the only back affordance), slot for bar actions, and a
 * snackbar host driven by one-shot message strings from the ViewModels.
 */
@Composable
fun AdminScaffold(
    title: String,
    onBack: () -> Unit,
    actions: @Composable RowScope.() -> Unit = {},
    snack: String? = null,
    onSnackShown: () -> Unit = {},
    /** False on root screens (dashboard) where there is nowhere to go back to. */
    showBack: Boolean = true,
    content: @Composable (PaddingValues) -> Unit,
) {
    val snackbarHostState = remember { SnackbarHostState() }

    // A null message means "nothing to show"; showing consumes the message
    // so the same success text does not replay on the next recomposition.
    LaunchedEffect(snack) {
        if (snack != null) {
            snackbarHostState.showSnackbar(snack)
            onSnackShown()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = title,
                        // Detail screens rely on this bar as the only page
                        // heading, so it carries title weight rather than the
                        // lighter default.
                        style = MaterialTheme.typography.titleLarge,
                    )
                },
                navigationIcon = {
                    if (showBack) {
                        IconButton(onClick = onBack) {
                            Icon(
                                Icons.AutoMirrored.Filled.ArrowBack,
                                contentDescription = "ফিরে যান",
                            )
                        }
                    }
                },
                actions = actions,
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.surface,
                    titleContentColor = MaterialTheme.colorScheme.onSurface,
                    actionIconContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
                    navigationIconContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
                ),
            )
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
        content = content,
    )
}
