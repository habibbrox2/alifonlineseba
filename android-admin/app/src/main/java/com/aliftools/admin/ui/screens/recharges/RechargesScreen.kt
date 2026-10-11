package com.aliftools.admin.ui.screens.recharges

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
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.data.model.AdminTopup
import com.aliftools.admin.ui.components.PagedListView
import com.aliftools.admin.ui.viewmodel.RechargesViewModel

private val STATUS_CHIPS = listOf(
    "" to "সব",
    "pending" to "পেন্ডিং",
    "review" to "রিভিউ",
    "approved" to "অনুমোদিত",
    "rejected" to "বাতিল",
)

private val STATUS_LABELS = STATUS_CHIPS.toMap()

@Composable
fun RechargesScreen(
    onBack: () -> Unit,
    viewModel: RechargesViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val detail by viewModel.detail.collectAsState()

    PagedListView(
        title = "রিচার্জ",
        state = state,
        onBack = onBack,
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
        // The list route filters by status only — no `q` to search on.
        showSearch = false,
        itemKey = { it.id },
    ) { row ->
        RechargeRow(row = row, onClick = { viewModel.openDetail(row) })
    }

    detail?.let { row ->
        RechargeDetailDialog(
            row = row,
            busy = busy,
            onDismiss = viewModel::closeDetail,
            onApprove = { viewModel.approve(row) },
            onReject = { note -> viewModel.reject(row, note) },
        )
    }
}

@Composable
private fun RechargeRow(row: AdminTopup, onClick: () -> Unit) {
    Card(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    text = "${row.username ?: "-"} · ৳${row.amount}",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                Text(
                    text = STATUS_LABELS[row.status] ?: row.status,
                    style = MaterialTheme.typography.labelMedium,
                    color = when (row.status) {
                        "approved" -> MaterialTheme.colorScheme.primary
                        "rejected" -> MaterialTheme.colorScheme.error
                        else -> MaterialTheme.colorScheme.tertiary
                    },
                )
            }
            Text(
                text = listOfNotNull(
                    row.method,
                    row.reference?.takeIf { it.isNotBlank() },
                    row.senderNumber,
                ).joinToString(" · ").ifBlank { "—" },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = row.createdAt ?: "",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@Composable
private fun RechargeDetailDialog(
    row: AdminTopup,
    busy: Boolean,
    onDismiss: () -> Unit,
    onApprove: () -> Unit,
    onReject: (String) -> Unit,
) {
    var note by remember(row.id) { mutableStateOf("") }
    var rejecting by remember(row.id) { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("রিচার্জ #${row.id}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                DetailLine("ইউজার", row.username ?: "-")
                DetailLine("পরিমাণ", "৳${row.amount}")
                DetailLine("মাধ্যম", row.method ?: "-")
                DetailLine("প্রেরক নম্বর", row.senderNumber ?: "-")
                DetailLine("রেফারেন্স", row.reference ?: "-")
                DetailLine("নোট", row.note ?: "-")
                DetailLine("স্ট্যাটাস", STATUS_LABELS[row.status] ?: row.status)
                DetailLine("তারিখ", row.createdAt ?: "-")

                if (row.status == "pending" || row.status == "review") {
                    if (rejecting) {
                        OutlinedTextField(
                            value = note,
                            onValueChange = { note = it },
                            label = { Text("বাতিলের কারণ") },
                            modifier = Modifier.padding(top = 8.dp),
                            enabled = !busy,
                            minLines = 2,
                        )
                    }
                } else {
                    Text(
                        text = "এই রিকোয়েস্টের সিদ্ধান্ত নেওয়া হয়েছে।",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(top = 8.dp),
                    )
                }
            }
        },
        confirmButton = {
            when {
                row.status == "pending" || row.status == "review" ->
                    if (rejecting) {
                        TextButton(
                            onClick = { onReject(note.trim()) },
                            enabled = !busy && note.isNotBlank(),
                        ) { Text("বাতিল নিশ্চিত") }
                    } else {
                        TextButton(onClick = onApprove, enabled = !busy) { Text("অনুমোদন") }
                    }
                else -> TextButton(onClick = onDismiss) { Text("বন্ধ") }
            }
        },
        dismissButton = {
            when {
                row.status == "pending" || row.status == "review" ->
                    if (rejecting) {
                        TextButton(onClick = { rejecting = false }, enabled = !busy) {
                            Text("ফিরে যান")
                        }
                    } else {
                        TextButton(onClick = { rejecting = true }, enabled = !busy) {
                            Text("বাতিল")
                        }
                    }
                else -> {}
            }
        },
    )
}

@Composable
private fun DetailLine(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(
            text = label,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(text = value, style = MaterialTheme.typography.bodyMedium)
    }
}
