package com.aliftools.admin.ui.screens.orders

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.data.model.AdminOrder
import com.aliftools.admin.ui.components.PagedListView
import com.aliftools.admin.ui.viewmodel.OrdersViewModel

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
    viewModel: OrdersViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val selected by viewModel.selected.collectAsState()

    PagedListView(
        title = "অর্ডার",
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
        filters = SCOPES,
        itemKey = { it.id },
    ) { order ->
        OrderRow(
            order = order,
            checked = order.id in selected,
            onToggle = { viewModel.toggleSelected(order.id) },
            onAction = { status -> viewModel.settleSelectedStatus(order.id, status) },
            busy = busy,
        )
    }

    if (selected.isNotEmpty()) {
        SettleBar(
            count = selected.size,
            busy = busy,
            onSettle = { status -> viewModel.settleSelected(status) },
            onClear = viewModel::clearSelection,
        )
    }
}

@Composable
private fun OrderRow(
    order: AdminOrder,
    checked: Boolean,
    onToggle: () -> Unit,
    onAction: (String) -> Unit = {},
    busy: Boolean = false,
) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Checkbox(checked = checked, onCheckedChange = { onToggle() })
                Column(modifier = Modifier.padding(vertical = 10.dp, horizontal = 4.dp).weight(1f)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Text(
                            text = "#${order.id} ${order.service ?: ""}".trim(),
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                        )
                        StatusChip(order.status)
                    }
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
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .padding(horizontal = 12.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                androidx.compose.material3.OutlinedButton(onClick = { onAction("processing") }, enabled = !busy) {
                    Text("প্রসেসিং")
                }
                androidx.compose.material3.OutlinedButton(onClick = { onAction("completed") }, enabled = !busy) {
                    Text("সম্পন্ন")
                }
                androidx.compose.material3.OutlinedButton(onClick = { onAction("cancelled") }, enabled = !busy) {
                    Text("বাতিল")
                }
                androidx.compose.material3.OutlinedButton(onClick = { onAction("failed") }, enabled = !busy) {
                    Text("ব্যর্থ")
                }
            }
        }
    }
}

@Composable
private fun StatusChip(status: String) {
    val label = SETTLE_STATUSES.firstOrNull { it.first == status }?.second ?: status
    Text(
        text = label,
        style = MaterialTheme.typography.labelMedium,
        color = when (status) {
            "completed" -> MaterialTheme.colorScheme.primary
            "failed", "cancelled" -> MaterialTheme.colorScheme.error
            else -> MaterialTheme.colorScheme.tertiary
        },
    )
}

/**
 * The selection action bar: pick a target status and the chosen rows go to
 * the bulk settle queue, exactly like the web desk's bulk form.
 */
@Composable
private fun SettleBar(count: Int, busy: Boolean, onSettle: (String) -> Unit, onClear: () -> Unit) {
    Surface(tonalElevation = 4.dp, shadowElevation = 8.dp) {
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
