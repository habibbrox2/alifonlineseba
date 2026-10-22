package com.aliftools.admin.ui.screens.dashboard

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.TextButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.Alignment
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.hilt.navigation.compose.hiltViewModel
import android.content.Intent
import android.net.Uri
import com.aliftools.admin.ui.components.AdminScaffold
import com.aliftools.admin.ui.viewmodel.DashboardUiState
import com.aliftools.admin.ui.viewmodel.DashboardViewModel

@Composable
fun DashboardScreen(
    onNavigateToUsers: () -> Unit,
    onNavigateToOrders: () -> Unit,
    onNavigateToRecharges: () -> Unit,
    onNavigateToWithdraws: () -> Unit,
    onNavigateToSettings: () -> Unit,
    onNavigateToLogs: () -> Unit,
    onNavigateToNotifications: () -> Unit,
    onNavigateToStaff: () -> Unit,
    onLoggedOut: () -> Unit,
    viewModel: DashboardViewModel = hiltViewModel()
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val loggedOut by viewModel.loggedOut.collectAsState()
    val role by viewModel.role.collectAsState(initial = null)
    val update by viewModel.update.collectAsState()
    val updateRequired by viewModel.updateRequired.collectAsState()
    val context = LocalContext.current

    // Logout finished: leave the authed part of the graph behind entirely,
    // so the back button cannot walk into a cleared session.
    LaunchedEffect(loggedOut) {
        if (loggedOut) onLoggedOut()
    }

    AdminScaffold(
        title = "ড্যাশবোর্ড",
        onBack = {},
        showBack = false,
        snack = snack,
        onSnackShown = viewModel::clearSnack,
        actions = {
            IconButton(onClick = viewModel::logout, enabled = !busy) {
                Icon(
                    Icons.AutoMirrored.Filled.Logout,
                    contentDescription = "লগআউট",
                )
            }
        },
    ) { padding ->
        when (state) {
            is DashboardUiState.Loading -> {
                Column(
                    modifier = Modifier
                        .fillMaxSize().padding(padding)
                ) {
                    CircularProgressIndicator(
                        // Accessible: spinner state for screen readers
                    )
                }
            }

            is DashboardUiState.Error -> {
                Card(
                    elevation = CardDefaults.cardElevation(defaultElevation = 8.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.errorContainer,
                    ),
                ) {
                    Column(
                        modifier = Modifier.padding(16.dp),
                        verticalArrangement = Arrangement.Center,
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Text(
                            text = (state as DashboardUiState.Error).message,
                            color = MaterialTheme.colorScheme.onError,
                            style = MaterialTheme.typography.titleMedium,
                        )
                        HorizontalDivider(
                            modifier = Modifier.padding(top = 8.dp),
                            color = MaterialTheme.colorScheme.outline,
                        )
                        // Accessible action buttons
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.Center,
                        ) {
                            Button(onClick = { viewModel.load() }) {
                                Text("পুনরায় চেষ্টা করুন")
                            }
                            Button(onClick = { /* Navigate to logs to see error details */ }) {
                                Text("ত্রুটির বিচার")
                            }
                        }
                    }
                }
            }

            is DashboardUiState.Success -> {
                val data = (state as DashboardUiState.Success).data
                LazyColumn(
                    modifier = Modifier.fillMaxSize().padding(padding),
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    // Update card with accessibility
                    update?.let { av ->
                        item {
                            Card(
                                shape = MaterialTheme.shapes.large,
                            ) {
                                Column(modifier = Modifier.padding(14.dp)) {
                                    Text(
                                        text = "একটি নতুন আপডেট উপলব্ধ: ${av.versionName}",
                                        style = MaterialTheme.typography.titleMedium,
                                        color = MaterialTheme.colorScheme.onSurface,
                                    )
                                    if (!av.releaseNotes.isNullOrBlank()) {
                                        Text(
                                            text = av.releaseNotes,
                                            style = MaterialTheme.typography.bodyMedium,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }
                                    Button(
                                        onClick = {
                                            val url = av.downloadUrl ?: "https://your-domain.com/app/apk"
                                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                                        },
                                        modifier = Modifier.padding(top = 8.dp),
                                    ) {
                                        Text("আপডেট করুন")
                                    }
                                }
                            }
                        }
                    }

                    item {
                        MetricGrid(
                            userCount = data.userCount,
                            openOrders = data.openOrders.count,
                            unresolvedCount = data.unresolvedCount,
                            unresolvedAmount = data.unresolvedAmount,
                        )
                    }

                    if (updateRequired && update != null) {
                        item {
                            AlertDialog(
                                onDismissRequest = {},
                                title = { Text("আপডেট প্রয়োজন") },
                                text = { Text(update?.releaseNotes ?: "") },
                                confirmButton = {
                                    TextButton(onClick = {
                                        val url = update?.downloadUrl ?: "https://your-domain.com/app/apk"
                                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                                    }) { Text("ডাউনলোড করুন") }
                                },
                                dismissButton = { TextButton(onClick = {}) { Text("পরে") } }
                            )
                        }
                    }
                }
            }

            else -> { /* Idle */ }
        }
    }
}

@Composable
private fun MetricGrid(
    userCount: Int,
    openOrders: Int,
    unresolvedCount: Int,
    unresolvedAmount: String,
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                MetricCard(
                    title = "মোট ইউজার",
                    value = userCount.toString(),
                    modifier = Modifier.weight(1f),
                )
                MetricCard(
                    title = "অ্যাক্টিভ অর্ডার",
                    value = openOrders.toString(),
                    modifier = Modifier.weight(1f),
                )
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                MetricCard(
                    title = "পেন্ডিং রিচার্জ",
                    value = unresolvedCount.toString(),
                    modifier = Modifier.weight(1f),
                )
                MetricCard(
                    title = "অনরক্ষিত Amount",
                    value = unresolvedAmount,
                    modifier = Modifier.weight(1f),
                )
            }
        }
    }

@Composable
private fun MetricCard(title: String, value: String, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier,
        shape = MaterialTheme.shapes.large,
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surface,
        ),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            // The label sits above a hairline so the number reads as the
            // subject of the card, which is how an operator scans it.
            Text(
                text = title,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            HorizontalDivider(
                modifier = Modifier.padding(vertical = 6.dp),
                color = MaterialTheme.colorScheme.outlineVariant,
            )
            Text(
                text = value,
                style = MaterialTheme.typography.headlineSmall,
                color = MaterialTheme.colorScheme.primary,
            )
        }
    }
}

@Composable
private fun QuickActions(
    showSuperAdmin: Boolean,
    onUsers: () -> Unit,
    onOrders: () -> Unit,
    onRecharges: () -> Unit,
    onWithdraws: () -> Unit,
    onSettings: () -> Unit,
    onLogs: () -> Unit,
    onNotifications: () -> Unit,
    onStaff: () -> Unit,
) {
    // Filled for the two queues an operator checks most (users, orders),
    // tonal for the rest — a wall of identical buttons hides the priority.
    val primary = ButtonDefaults.buttonColors()
    val tonal = ButtonDefaults.filledTonalButtonColors()

    // Ensure minimum 48dp touch target accessibility
    val minTouchTarget = 48.dp

    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(
            modifier = Modifier.fillMaxWidth()
        ) {
            Button(
                onClick = onUsers,
                colors = primary,
                modifier = Modifier
                    .weight(1f)
                    .height(minTouchTarget)
                ,shape = MaterialTheme.shapes.medium,
            ) {
                Text("ইউজার")
            }
            Button(
                onClick = onOrders,
                colors = primary,
                modifier = Modifier
                    .weight(1f)
                    .height(minTouchTarget)
                ,shape = MaterialTheme.shapes.medium,
            ) {
                Text("অর্ডার")
            }
        }
        Row(
            modifier = Modifier.fillMaxWidth()
        ) {
            Button(
                onClick = onRecharges,
                colors = tonal,
                modifier = Modifier
                    .weight(1f)
                    .height(minTouchTarget)
                ,shape = MaterialTheme.shapes.medium,
            ) {
                Text("রিচার্জ")
            }
            Button(
                onClick = onLogs,
                colors = tonal,
                modifier = Modifier
                    .weight(1f)
                    .height(minTouchTarget)
                ,shape = MaterialTheme.shapes.medium,
            ) {
                Text("লগ")
            }
        }
        Row(
            modifier = Modifier.fillMaxWidth()
        ) {
            Button(
                onClick = onNotifications,
                colors = tonal,
                modifier = Modifier
                    .weight(1f)
                    .height(minTouchTarget)
                ,shape = MaterialTheme.shapes.medium,
            ) {
                Text("নোটিফিকেশন")
            }
            if (showSuperAdmin) {
                Button(
                    onClick = onStaff,
                    colors = tonal,
                    modifier = Modifier
                        .weight(1f)
                        .height(minTouchTarget)
                    ,shape = MaterialTheme.shapes.medium,
                ) {
                    Text("স্টাফ")
                }
            }
        }
        if (showSuperAdmin) {
            Row(
                modifier = Modifier.fillMaxWidth()
            ) {
                Button(
                    onClick = onWithdraws,
                    colors = tonal,
                    modifier = Modifier
                        .weight(1f)
                        .height(minTouchTarget)
                    ,shape = MaterialTheme.shapes.medium,
                ) {
                    Text("উত্তোলন")
                }
                Button(
                    onClick = onSettings,
                    colors = tonal,
                    modifier = Modifier
                        .weight(1f)
                        .height(minTouchTarget)
                    ,shape = MaterialTheme.shapes.medium,
                ) {
                    Text("সেটিংস")
                }
            }
        }
    }
}

