package com.aliftools.admin.ui.screens.notifications

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
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
import com.aliftools.admin.data.model.AdminNotification
import com.aliftools.admin.ui.components.PagedListView
import com.aliftools.admin.ui.viewmodel.NotificationsViewModel

private val STATUS_CHIPS = listOf(
    "" to "সব",
    "queued" to "কিউ",
    "processing" to "চলছে",
    "sent" to "পাঠানো",
    "dead" to "ডেড",
)

private val STATUS_LABELS = STATUS_CHIPS.toMap()

@Composable
fun NotificationsScreen(
    onBack: () -> Unit,
    viewModel: NotificationsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()

    PagedListView(
        title = "নোটিফিকেশন কিউ",
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
        showSearch = true,
        itemKey = { it.id },
    ) { row ->
        NotificationRow(
            row = row,
            busy = busy,
            onRetry = { viewModel.retry(row) },
            onDelete = { viewModel.delete(row) },
        )
    }
}

@Composable
private fun NotificationRow(row: AdminNotification, busy: Boolean, onRetry: () -> Unit, onDelete: () -> Unit) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = row.event ?: "#${row.id}",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                Text(
                    text = STATUS_LABELS[row.status] ?: row.status,
                    style = MaterialTheme.typography.labelMedium,
                    color = when (row.status) {
                        "sent" -> MaterialTheme.colorScheme.primary
                        "dead" -> MaterialTheme.colorScheme.error
                        else -> MaterialTheme.colorScheme.tertiary
                    },
                )
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    text = listOfNotNull(
                        row.channel,
                        row.attempts?.let { "চেষ্টা $it" },
                        row.createdAt,
                    ).joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (row.status == "dead") {
                    TextButton(onClick = onRetry, enabled = !busy) {
                        Text("আবার চালান", style = MaterialTheme.typography.labelMedium)
                    }
                }
                if (row.status == "sent" || row.status == "dead") {
                    TextButton(onClick = onDelete, enabled = !busy) {
                        Text("মুছে ফেলুন", style = MaterialTheme.typography.labelMedium)
                    }
                }
            }
            row.lastError?.takeIf { it.isNotBlank() }?.let { error ->
                Text(
                    text = error,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.error,
                    maxLines = 2,
                )
            }
        }
    }
}
