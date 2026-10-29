package com.aliftools.admin.ui.screens.withdraws

import androidx.biometric.BiometricManager
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import com.aliftools.admin.data.model.AdminWithdraw
import com.aliftools.admin.ui.components.PagedListView
import com.aliftools.admin.ui.components.design.StatusPill
import com.aliftools.admin.ui.format.Dates
import com.aliftools.admin.ui.format.Money
import com.aliftools.admin.ui.viewmodel.WithdrawsViewModel
import com.aliftools.admin.util.BiometricHelper

private val STATUS_CHIPS = listOf(
    "" to "সব",
    "pending" to "পেন্ডিং",
    "review" to "রিভিউ",
    "approved" to "অনুমোদিত",
    "rejected" to "বাতিল",
)

private val STATUS_LABELS = STATUS_CHIPS.toMap()

@Composable
fun WithdrawsScreen(
    onBack: () -> Unit,
    showBack: Boolean = true,
    viewModel: WithdrawsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val detail by viewModel.detail.collectAsState()

    PagedListView(
        title = "উত্তোলন",
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
        filters = STATUS_CHIPS,
        itemKey = { it.id },
    ) { row ->
        WithdrawRow(row = row, onClick = { viewModel.openDetail(row) })
    }

    detail?.let { row ->
        val context2 = LocalContext.current
        val biometric = remember { BiometricHelper(context2.applicationContext) }
        WithdrawDetailDialog(
            row = row,
            busy = busy,
            onDismiss = viewModel::closeDetail,
            onApprove = {
                val activity = context2 as? FragmentActivity
                if (activity != null && biometric.isBiometricAvailable() == BiometricManager.BIOMETRIC_SUCCESS) {
                    biometric.showPrompt(activity, onSuccess = { viewModel.approve(row) }, onFailure = {})
                } else {
                    viewModel.approve(row)
                }
            },
            onReject = { reason -> viewModel.reject(row, reason) },
            onClaim = { viewModel.claim(row) },
            onRelease = { viewModel.release(row) },
        )
    }
}

@Composable
private fun WithdrawRow(row: AdminWithdraw, onClick: () -> Unit) {
    Card(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(14.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = "${row.username ?: "অ্যাডমিন #${row.adminId}"} · ${Money.format(row.amount)}",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
                StatusPill(row.status)
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = Dates.relative(row.createdAt) ?: (row.createdAt ?: ""),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    text = row.reviewedAt?.let { "রিভিউ: $it" } ?: "রিভিউ হয়নি",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

@Composable
private fun WithdrawDetailDialog(
    row: AdminWithdraw,
    busy: Boolean,
    onDismiss: () -> Unit,
    onApprove: () -> Unit,
    onReject: (String) -> Unit,
    onClaim: () -> Unit,
    onRelease: () -> Unit,
) {
    var reason by remember(row.id) { mutableStateOf("") }
    var rejecting by remember(row.id) { mutableStateOf(false) }

    val open = row.status == "pending" || row.status == "review"

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("উত্তোলন #${row.id}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Line("অ্যাডমিন", row.username ?: "#${row.adminId}")
                Line("পরিমাণ", Money.format(row.amount))
                Line("বর্তমান ব্যালেন্স", Money.format(row.adminBalance))
                Line("স্ট্যাটাস", STATUS_LABELS[row.status] ?: row.status)
                Line("তারিখ", row.createdAt ?: "-")
                Line("রিভিউয়ার", row.reviewedBy?.let { "#$it" } ?: "-")

                if (open && rejecting) {
                    OutlinedTextField(
                        value = reason,
                        onValueChange = { reason = it },
                        label = { Text("বাতিলের কারণ (আবশ্যক)") },
                        modifier = Modifier.padding(top = 8.dp),
                        enabled = !busy,
                        minLines = 2,
                    )
                }
                if (rejecting && reason.isNotBlank()) {
                    Text(
                        text = "বাতিল করলে টাকা ব্যালেন্সে ফিরবে।",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.error,
                    )
                }
            }
        },
        confirmButton = {
            when {
                !open -> TextButton(onClick = onDismiss) { Text("বন্ধ") }
                rejecting -> TextButton(
                    onClick = { onReject(reason.trim()) },
                    enabled = !busy && reason.isNotBlank(),
                ) { Text("বাতিল নিশ্চিত") }
                else -> TextButton(onClick = onApprove, enabled = !busy) { Text("অনুমোদন") }
            }
        },
        dismissButton = {
            if (open) {
                if (rejecting) {
                    TextButton(onClick = { rejecting = false }, enabled = !busy) {
                        Text("ফিরে যান")
                    }
                } else {
                    Row(horizontalArrangement = Arrangement.spacedBy(0.dp)) {
                        TextButton(onClick = onClaim, enabled = !busy) { Text("ধরুন") }
                        TextButton(onClick = onRelease, enabled = !busy && row.status == "review") {
                            Text("ছাড়ুন")
                        }
                        TextButton(onClick = { rejecting = true }, enabled = !busy) {
                            Text("বাতিল")
                        }
                    }
                }
            }
        },
    )
}

@Composable
private fun Line(label: String, value: String) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(text = value, style = MaterialTheme.typography.bodyMedium)
    }
}
