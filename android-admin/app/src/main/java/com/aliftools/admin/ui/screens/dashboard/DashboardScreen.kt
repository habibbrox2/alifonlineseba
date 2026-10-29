package com.aliftools.admin.ui.screens.dashboard

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.clickable
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.outlined.AccountBalanceWallet
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Payment
import androidx.compose.material.icons.outlined.ReceiptLong
import androidx.compose.material.icons.outlined.Send
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.data.model.ActivityLog
import com.aliftools.admin.data.model.AdminDashboard
import com.aliftools.admin.ui.components.AdminScaffold
import com.aliftools.admin.ui.components.design.ErrorState
import com.aliftools.admin.ui.components.design.QueueItem
import com.aliftools.admin.ui.components.design.QueueRail
import com.aliftools.admin.ui.components.design.RoleChip
import com.aliftools.admin.ui.components.design.SectionHeader
import com.aliftools.admin.ui.components.design.StatTile
import com.aliftools.admin.ui.components.design.StatTone
import com.aliftools.admin.ui.format.Dates
import com.aliftools.admin.ui.format.Money
import com.aliftools.admin.ui.theme.AlifTheme
import com.aliftools.admin.ui.viewmodel.DashboardUiState
import com.aliftools.admin.ui.viewmodel.DashboardViewModel

/**
 * The Home screen — "কর্মডেস্ক" (Work Desk).
 *
 * Implements the locked v1.0 design contract per §6.2:
 * - Emerald hero banner with user greeting, role chip, and unresolved count/amount
 * - Signature Queue Rail overlapping the hero (orders, recharges, withdraws)
 * - 2x2 StatTile metric cards
 * - Quick action cards
 * - Recent activity audit trail with relative timestamps
 */
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
    onNavigateToCategories: () -> Unit = {},
    onNavigateToServices: () -> Unit = {},
    onNavigateToLedger: () -> Unit = {},
    onNavigateToReferrals: () -> Unit = {},
    onNavigateToRoleHierarchy: () -> Unit = {},
    onNavigateToPermissionsMatrix: () -> Unit = {},
    onNavigateToProfile: () -> Unit = {},
    onLoggedOut: () -> Unit,
    viewModel: DashboardViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val refreshing by viewModel.refreshing.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val loggedOut by viewModel.loggedOut.collectAsState()
    val role by viewModel.role.collectAsState(initial = null)
    val update by viewModel.update.collectAsState()
    val updateRequired by viewModel.updateRequired.collectAsState()
    val context = LocalContext.current
    var updateDismissed by remember { mutableStateOf(false) }

    LaunchedEffect(loggedOut) {
        if (loggedOut) onLoggedOut()
    }

    AdminScaffold(
        title = "হোম",
        onBack = {},
        showBack = false,
        snack = snack,
        onSnackShown = viewModel::clearSnack,
        actions = {
            IconButton(onClick = { viewModel.refresh() }, enabled = !busy && !refreshing) {
                if (refreshing) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                        color = MaterialTheme.colorScheme.onPrimary,
                    )
                } else {
                    Icon(
                        Icons.Default.Refresh,
                        contentDescription = "রিফ্রেশ",
                    )
                }
            }
        },
    ) { padding ->
        when (val s = state) {
            is DashboardUiState.Loading -> {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(padding),
                    contentAlignment = Alignment.Center,
                ) {
                    CircularProgressIndicator()
                }
            }
            is DashboardUiState.Error -> {
                ErrorState(
                    message = s.message,
                    onRetry = viewModel::load,
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(padding),
                )
            }
            is DashboardUiState.Success -> {
                DashboardContent(
                    data = s.data,
                    role = role,
                    onNavigateToOrders = onNavigateToOrders,
                    onNavigateToRecharges = onNavigateToRecharges,
                    onNavigateToWithdraws = onNavigateToWithdraws,
                    onNavigateToUsers = onNavigateToUsers,
                    onNavigateToLogs = onNavigateToLogs,
                    onNavigateToNotifications = onNavigateToNotifications,
                    onNavigateToProfile = onNavigateToProfile,
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(padding),
                )
            }
        }
    }

    // App update announcement
    val offer = update
    if (offer != null && !updateDismissed) {
        AlertDialog(
            onDismissRequest = { if (!updateRequired) updateDismissed = true },
            title = { Text("নতুন সংস্করণ উপলব্ধ: v${offer.versionName}") },
            text = {
                Text(
                    offer.releaseNotes?.ifBlank { null }
                        ?: if (updateRequired) "এই সংস্করণটি চালানো চালিয়ে যাওয়ার জন্য অ্যাপটি আপডেট করতে হবে।"
                        else "একটি নতুন সংস্করণ ডাউনলোড করার জন্য উপলব্ধ রয়েছে।"
                )
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        val url = offer.downloadUrl
                        if (!url.isNullOrBlank()) {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                        }
                    }
                ) {
                    Text("আপডেট করুন")
                }
            },
            dismissButton = if (!updateRequired) {
                {
                    TextButton(onClick = { updateDismissed = true }) {
                        Text("পরে")
                    }
                }
            } else null,
        )
    }
}

@Composable
private fun DashboardContent(
    data: AdminDashboard,
    role: String?,
    onNavigateToOrders: () -> Unit,
    onNavigateToRecharges: () -> Unit,
    onNavigateToWithdraws: () -> Unit,
    onNavigateToUsers: () -> Unit,
    onNavigateToLogs: () -> Unit,
    onNavigateToNotifications: () -> Unit = {},
    onNavigateToProfile: () -> Unit = {},
    modifier: Modifier = Modifier,
) {
    val colors = AlifTheme.colors

    LazyColumn(
        modifier = modifier.fillMaxSize(),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        // Emerald Hero Header
        item {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(
                        Brush.verticalGradient(
                            colors = listOf(colors.heroStart, colors.heroEnd),
                        )
                    )
                    .padding(horizontal = 16.dp, vertical = 20.dp),
            ) {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    // Top row: Greeting + RoleChip + Bell
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(10.dp),
                            modifier = Modifier.clickable(onClick = onNavigateToProfile),
                        ) {
                            Box(
                                modifier = Modifier
                                    .size(38.dp)
                                    .background(colors.gold, CircleShape),
                                contentAlignment = Alignment.Center,
                            ) {
                                Text(
                                    text = (role?.take(2)?.uppercase() ?: "AD"),
                                    style = MaterialTheme.typography.labelLarge,
                                    color = colors.onGold,
                                    fontWeight = FontWeight.Bold,
                                )
                            }
                            Column {
                                Text(
                                    text = "শুভ দিন",
                                    style = MaterialTheme.typography.titleMedium,
                                    color = Color.White,
                                    fontWeight = FontWeight.Bold,
                                )
                                RoleChip(role = role ?: "admin")
                            }
                        }

                        IconButton(
                            onClick = onNavigateToNotifications,
                            modifier = Modifier.size(36.dp),
                        ) {
                            Icon(
                                imageVector = Icons.Outlined.Notifications,
                                contentDescription = "নোটিফিকেশন",
                                tint = Color.White,
                                modifier = Modifier.size(24.dp),
                            )
                        }
                    }

                    Spacer(Modifier.height(4.dp))

                    // Summary Line
                    Column {
                        Text(
                            text = "আজকের কাজ",
                            style = MaterialTheme.typography.labelSmall,
                            color = Color.White.copy(alpha = 0.8f),
                        )
                        Text(
                            text = "${data.unresolvedCount}টি কাজ · ${Money.format(data.unresolvedAmount)} অপেক্ষায়",
                            style = MaterialTheme.typography.headlineSmall,
                            color = Color.White,
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }
            }
        }

        // Queue Rail
        item {
            val queueItems = listOf(
                QueueItem("orders", "অর্ডার", data.openOrders.count),
                QueueItem("recharges", "রিচার্জ", data.unresolvedCount),
                QueueItem("withdraws", "উত্তোলন", 0),
            )
            Box(modifier = Modifier.padding(horizontal = 16.dp)) {
                QueueRail(
                    items = queueItems,
                    onSelect = { key ->
                        when (key) {
                            "orders" -> onNavigateToOrders()
                            "recharges" -> onNavigateToRecharges()
                            "withdraws" -> onNavigateToWithdraws()
                        }
                    },
                )
            }
        }

        // 2x2 Metric tiles
        item {
            Column(
                modifier = Modifier.padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                SectionHeader(title = "সংক্ষিপ্ত হিসাব")
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    StatTile(
                        icon = Icons.Outlined.Group,
                        value = "${data.userCount}",
                        label = "মোট ইউজার",
                        modifier = Modifier.weight(1f),
                        tone = StatTone.Brand,
                    )
                    StatTile(
                        icon = Icons.Outlined.Inbox,
                        value = "${data.openOrders.count}",
                        label = "সক্রিয় অর্ডার",
                        modifier = Modifier.weight(1f),
                        tone = StatTone.Info,
                    )
                }
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    StatTile(
                        icon = Icons.Outlined.AccountBalanceWallet,
                        value = Money.format("${data.txStats?.credit ?: 0.0}"),
                        label = "মোট ক্রেডিট",
                        modifier = Modifier.weight(1f),
                        tone = StatTone.Gold,
                    )
                    StatTile(
                        icon = Icons.Outlined.CheckCircle,
                        value = "${data.topupStats?.approved ?: 0}",
                        label = "অনুমোদিত রিচার্জ",
                        modifier = Modifier.weight(1f),
                        tone = StatTone.Success,
                    )
                }
            }
        }

        // Quick Actions Grid
        item {
            Column(
                modifier = Modifier.padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                SectionHeader(title = "দ্রুত কাজ")
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    QuickActionCard(
                        icon = Icons.Outlined.Inbox,
                        title = "অর্ডার",
                        caption = "${data.openOrders.count}টি অপেক্ষমাণ",
                        onClick = onNavigateToOrders,
                        modifier = Modifier.weight(1f),
                    )
                    QuickActionCard(
                        icon = Icons.Outlined.Payment,
                        title = "রিচার্জ",
                        caption = "${data.unresolvedCount}টি অনুরোধ",
                        onClick = onNavigateToRecharges,
                        modifier = Modifier.weight(1f),
                    )
                }
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    QuickActionCard(
                        icon = Icons.Outlined.Group,
                        title = "ইউজার",
                        caption = "খুঁজুন ও পরিচালনা",
                        onClick = onNavigateToUsers,
                        modifier = Modifier.weight(1f),
                    )
                    if (role == "superadmin") {
                        QuickActionCard(
                            icon = Icons.Outlined.Send,
                            title = "উত্তোলন",
                            caption = "পে-আউট কিউ",
                            onClick = onNavigateToWithdraws,
                            modifier = Modifier.weight(1f),
                        )
                    } else {
                        QuickActionCard(
                            icon = Icons.Outlined.Notifications,
                            title = "নোটিফিকেশন",
                            caption = "কিউ ও স্ট্যাটাস",
                            onClick = onNavigateToNotifications,
                            modifier = Modifier.weight(1f),
                        )
                    }
                }
            }
        }

        // Recent Activity
        if (data.recentLogs.isNotEmpty()) {
            item {
                Column(modifier = Modifier.padding(horizontal = 16.dp)) {
                    SectionHeader(
                        title = "সাম্প্রতিক কার্যক্রম",
                        actionLabel = "সব দেখুন",
                        onAction = onNavigateToLogs,
                    )
                }
            }
            items(data.recentLogs.take(5)) { log ->
                ActivityRow(
                    log = log,
                    modifier = Modifier.padding(horizontal = 16.dp),
                )
            }
        }

        item {
            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
private fun QuickActionCard(
    icon: ImageVector,
    title: String,
    caption: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(
        onClick = onClick,
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.colorScheme.surface,
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        modifier = modifier,
    ) {
        Row(
            modifier = Modifier.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(36.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(MaterialTheme.colorScheme.primaryContainer),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = icon,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.onPrimaryContainer,
                    modifier = Modifier.size(20.dp),
                )
            }
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = title,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
                Text(
                    text = caption,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                )
            }
            Icon(
                imageVector = Icons.AutoMirrored.Filled.KeyboardArrowRight,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(18.dp),
            )
        }
    }
}

@Composable
private fun ActivityRow(
    log: ActivityLog,
    modifier: Modifier = Modifier,
) {
    Surface(
        shape = MaterialTheme.shapes.medium,
        color = MaterialTheme.colorScheme.surface,
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        modifier = modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 14.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(32.dp)
                    .clip(CircleShape)
                    .background(MaterialTheme.colorScheme.primaryContainer),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = Icons.Outlined.ReceiptLong,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.onPrimaryContainer,
                    modifier = Modifier.size(16.dp),
                )
            }
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = log.description.ifBlank { log.action },
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.Medium,
                    maxLines = 1,
                )
                Text(
                    text = listOfNotNull(
                        log.action,
                        Dates.relative(log.createdAt) ?: log.createdAt,
                    ).joinToString(" · "),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}
