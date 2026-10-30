package com.aliftools.admin.ui.screens.orders

import androidx.biometric.BiometricManager
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Phone
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.data.model.AdminOrder
import com.aliftools.admin.data.model.OrderDetailEnvelope
import com.aliftools.admin.ui.components.PagedListView
import com.aliftools.admin.ui.components.design.StatusPill
import com.aliftools.admin.ui.format.Money
import com.aliftools.admin.ui.theme.AlifTheme
import com.aliftools.admin.ui.theme.Brand100
import com.aliftools.admin.ui.theme.Brand600
import com.aliftools.admin.ui.theme.Brand700
import com.aliftools.admin.ui.viewmodel.OrdersViewModel
import com.aliftools.admin.util.BiometricHelper

private val SCOPES = listOf("" to "সব অর্ডার", "mine" to "আমার", "taken" to "মুক্ত")

/** Bulk settle targets — StatusPresenter::REQUEST_STATUSES. */
private val SETTLE_STATUSES = listOf(
    "pending" to "পেন্ডিং",
    "processing" to "প্রসেসিং",
    "completed" to "সম্পন্ন",
    "failed" to "ব্যর্থ",
    "cancelled" to "বাতিল",
)

@Composable
fun OrdersScreen(
    onBack: () -> Unit,
    showBack: Boolean = true,
    viewModel: OrdersViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val selected by viewModel.selected.collectAsState()
    val detail by viewModel.detail.collectAsState()
    val loadingDetail by viewModel.loadingDetail.collectAsState()

    val context = LocalContext.current
    val biometric = remember { BiometricHelper(context.applicationContext) }

    fun doWithBiometric(onSuccess: () -> Unit) {
        val activity = context as? FragmentActivity
        if (activity != null && biometric.isBiometricAvailable() == BiometricManager.BIOMETRIC_SUCCESS) {
            biometric.showPrompt(
                activity,
                onSuccess = onSuccess,
                onFailure = { msg -> viewModel.showSnack(msg) },
            )
        } else {
            onSuccess()
        }
    }

    Box(modifier = Modifier.fillMaxSize()) {
        PagedListView(
            title = "অর্ডার",
            state = state,
            onBack = onBack,
            showBack = showBack,
            onRefresh = viewModel::reload,
            onLoadMore = viewModel::loadMore,
            onSearch = viewModel::search,
            onQueryChange = viewModel::onQueryChange,
            onRetry = viewModel::retry,
            busy = busy,
            snack = snack,
            onSnackShown = viewModel::clearSnack,
            onFilterChange = viewModel::onFilterChange,
            filters = SCOPES,
            itemKey = { it.id },
        ) { order ->
            OrderRow(
                order = order,
                checked = order.id in selected,
                onToggle = { viewModel.toggleSelected(order.id) },
                onClick = { viewModel.openDetail(order.id) },
                onReviewClick = { viewModel.openDetail(order.id) },
            )
        }

        if (selected.isNotEmpty()) {
            SettleBar(
                count = selected.size,
                busy = busy,
                onSettle = { status ->
                    doWithBiometric { viewModel.settleSelected(status) }
                },
                onClear = viewModel::clearSelection,
                modifier = Modifier.align(Alignment.BottomCenter),
            )
        }

        if (loadingDetail) {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .clickable(enabled = false) {},
                contentAlignment = Alignment.Center,
            ) {
                Surface(
                    shape = RoundedCornerShape(12.dp),
                    tonalElevation = 6.dp,
                    shadowElevation = 8.dp,
                ) {
                    CircularProgressIndicator(modifier = Modifier.padding(24.dp))
                }
            }
        }

        detail?.let { envelope ->
            OrderDetailDialog(
                envelope = envelope,
                busy = busy,
                onDismiss = viewModel::closeDetail,
                onClaim = { viewModel.claim(envelope.order.id) },
                onRelease = { viewModel.release(envelope.order.id) },
                onApprove = { note ->
                    doWithBiometric {
                        viewModel.approve(envelope.order.id, note)
                    }
                },
                onReject = { reason, note ->
                    doWithBiometric {
                        viewModel.reject(envelope.order.id, reason, note)
                    }
                },
                onSetStatus = { status, note ->
                    viewModel.setStatus(envelope.order.id, status, note)
                },
            )
        }
    }
}

@Composable
private fun OrderRow(
    order: AdminOrder,
    checked: Boolean,
    onToggle: () -> Unit,
    onClick: () -> Unit,
    onReviewClick: () -> Unit,
) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Checkbox(
                    checked = checked,
                    onCheckedChange = { onToggle() },
                    modifier = Modifier.size(32.dp),
                )
                Spacer(modifier = Modifier.size(8.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            text = "#${order.id} ${order.service ?: ""}".trim(),
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.SemiBold,
                        )
                        StatusPill(order.status)
                    }
                    Spacer(modifier = Modifier.height(4.dp))
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Text(
                            text = order.username ?: "-",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Text(
                            text = order.claimedByName?.let { "ধরা: $it" } ?: "মালিকহীন",
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = if (order.claimedByName != null) FontWeight.Medium else FontWeight.Normal,
                            color = if (order.claimedByName != null) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }

            Spacer(modifier = Modifier.height(8.dp))
            HorizontalDivider()
            Spacer(modifier = Modifier.height(4.dp))

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = order.createdAt ?: "",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Button(
                    onClick = onReviewClick,
                    shape = RoundedCornerShape(8.dp),
                    contentPadding = ButtonDefaults.TextButtonContentPadding,
                ) {
                    Text("রিভিউ / সিদ্ধান্ত")
                }
            }
        }
    }
}

@Composable
private fun OrderDetailDialog(
    envelope: OrderDetailEnvelope,
    busy: Boolean,
    onDismiss: () -> Unit,
    onClaim: () -> Unit,
    onRelease: () -> Unit,
    onApprove: (note: String) -> Unit,
    onReject: (reason: String, note: String) -> Unit,
    onSetStatus: (status: String, note: String) -> Unit,
) {
    val order = envelope.order
    val user = envelope.user
    val colors = AlifTheme.colors

    var adminNote by remember(order.id) { mutableStateOf("") }
    var rejectReason by remember(order.id) { mutableStateOf("") }
    var isRejecting by remember(order.id) { mutableStateOf(false) }

    val isOpen = order.status in listOf("pending", "review", "processing")

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = {
            Column {
                Text(
                    text = "অর্ডার #${order.id}",
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    text = order.service ?: "সার্ভিস অর্ডার",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        },
        text = {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                // Status & Claimer
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    StatusPill(order.status)
                    order.price?.let {
                        Text(
                            text = Money.format(it),
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.Bold,
                            color = Brand700,
                        )
                    }
                }

                // Claimer banner
                if (envelope.claimer != null) {
                    Surface(
                        modifier = Modifier.fillMaxWidth(),
                        color = if (envelope.isMine) Brand100 else MaterialTheme.colorScheme.errorContainer.copy(alpha = 0.5f),
                        shape = RoundedCornerShape(8.dp),
                    ) {
                        Text(
                            text = if (envelope.isMine) "এই অর্ডারটি আপনার নামে রিভিউতে রয়েছে।"
                            else "এই অর্ডারটি ${envelope.claimer.name ?: "অন্য একজন"} ধরে আছেন — তাঁর সিদ্ধান্তের জন্য অপেক্ষা করুন।",
                            style = MaterialTheme.typography.bodySmall,
                            fontWeight = FontWeight.Medium,
                            modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
                        )
                    }
                }

                // Customer info
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(8.dp),
                ) {
                    Column(
                        modifier = Modifier.padding(10.dp),
                        verticalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        Text(
                            text = "গ্রাহকের তথ্য",
                            style = MaterialTheme.typography.labelMedium,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.primary,
                        )
                        Text(text = "নাম: ${user?.name ?: order.username ?: "-"}", style = MaterialTheme.typography.bodySmall)
                        if (!user?.phone.isNullOrBlank()) {
                            Text(text = "ফোন: ${user?.phone}", style = MaterialTheme.typography.bodySmall)
                        }
                        if (!user?.email.isNullOrBlank()) {
                            Text(text = "ইমেইল: ${user?.email}", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }

                // Form Entries / Results
                if (!envelope.resultEntries.isNullOrEmpty()) {
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(8.dp),
                    ) {
                        Column(
                            modifier = Modifier.padding(10.dp),
                            verticalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            Text(
                                text = "জমা দেওয়া তথ্য",
                                style = MaterialTheme.typography.labelMedium,
                                fontWeight = FontWeight.Bold,
                                color = MaterialTheme.colorScheme.primary,
                            )
                            envelope.resultEntries.forEach { entry ->
                                Text(
                                    text = "${entry.label}: ${entry.value ?: "—"}",
                                    style = MaterialTheme.typography.bodySmall,
                                )
                            }
                        }
                    }
                }

                // Reject reason input
                if (isOpen && isRejecting) {
                    OutlinedTextField(
                        value = rejectReason,
                        onValueChange = { rejectReason = it },
                        label = { Text("বাতিলের কারণ (গ্রাহক দেখবেন)") },
                        modifier = Modifier.fillMaxWidth(),
                        enabled = !busy,
                        minLines = 2,
                    )
                }

                // Admin internal note
                if (isOpen && envelope.canDecide) {
                    OutlinedTextField(
                        value = adminNote,
                        onValueChange = { adminNote = it },
                        label = { Text("অ্যাডমিন নোট (ঐচ্ছিক)") },
                        modifier = Modifier.fillMaxWidth(),
                        enabled = !busy,
                        singleLine = true,
                    )
                }

                // Action buttons inside dialog
                if (isOpen) {
                    if (envelope.claimer == null) {
                        Button(
                            onClick = onClaim,
                            modifier = Modifier.fillMaxWidth(),
                            enabled = !busy,
                        ) {
                            Text("রিভিউ নিন (Claim)")
                        }
                    } else if (envelope.canDecide) {
                        if (!isRejecting) {
                            Button(
                                onClick = { onApprove(adminNote) },
                                modifier = Modifier.fillMaxWidth(),
                                colors = ButtonDefaults.buttonColors(containerColor = Brand600),
                                enabled = !busy,
                            ) {
                                Text("অনুমোদন করুন (কমিশন যোগ হবে)")
                            }

                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                OutlinedButton(
                                    onClick = { onSetStatus("processing", adminNote) },
                                    modifier = Modifier.weight(1f),
                                    enabled = !busy,
                                ) {
                                    Text("প্রসেসিং")
                                }
                                OutlinedButton(
                                    onClick = onRelease,
                                    modifier = Modifier.weight(1f),
                                    enabled = !busy,
                                ) {
                                    Text("ছেড়ে দিন")
                                }
                            }

                            Button(
                                onClick = { isRejecting = true },
                                modifier = Modifier.fillMaxWidth(),
                                colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error),
                                enabled = !busy,
                            ) {
                                Text("বাতিল ও রিফান্ড")
                            }
                        } else {
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                Button(
                                    onClick = { onReject(rejectReason, adminNote) },
                                    modifier = Modifier.weight(1f),
                                    colors = ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error),
                                    enabled = !busy && rejectReason.isNotBlank(),
                                ) {
                                    Text("নিশ্চিত বাতিল ও রিফান্ড")
                                }
                                TextButton(
                                    onClick = { isRejecting = false },
                                    enabled = !busy,
                                ) {
                                    Text("ফিরে যান")
                                }
                            }
                        }
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = {
            TextButton(
                onClick = onDismiss,
                enabled = !busy,
            ) {
                Text("বন্ধ করুন")
            }
        },
    )
}

@Composable
private fun SettleBar(
    count: Int,
    busy: Boolean,
    onSettle: (String) -> Unit,
    onClear: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Surface(tonalElevation = 4.dp, shadowElevation = 8.dp, modifier = modifier) {
        Column(modifier = Modifier.fillMaxWidth().padding(8.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = "$count টি নির্বাচিত",
                    style = MaterialTheme.typography.titleSmall,
                    modifier = Modifier.padding(start = 8.dp),
                )
                TextButton(onClick = onClear) { Text("বাতিল") }
            }
            Row(
                modifier = Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                SETTLE_STATUSES.forEach { (value, label) ->
                    TextButton(onClick = { onSettle(value) }, enabled = !busy) {
                        Text(label)
                    }
                }
            }
        }
    }
}
