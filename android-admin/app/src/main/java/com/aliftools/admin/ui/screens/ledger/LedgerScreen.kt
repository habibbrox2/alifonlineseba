package com.aliftools.admin.ui.screens.ledger

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.ui.components.AdminScaffold
import com.aliftools.admin.ui.viewmodel.LedgerViewModel

@Composable
fun LedgerScreen(
    onBack: () -> Unit,
    viewModel: LedgerViewModel = hiltViewModel(),
) {
    val data by viewModel.data.collectAsState()
    val loading by viewModel.loading.collectAsState()
    val snack by viewModel.snack.collectAsState()

    val rows = (data["rows"] as? List<*>)?.filterIsInstance<Map<String, Any?>>() ?: emptyList()

    AdminScaffold(
        title = "লেজার",
        onBack = onBack,
        snack = snack,
        onSnackShown = viewModel::clearSnack,
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (loading) {
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
                    CircularProgressIndicator()
                }
            }
            LazyColumn(modifier = Modifier.weight(1f).fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(rows.size) { idx ->
                    val row = rows[idx]
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Column(modifier = Modifier.padding(12.dp)) {
                            val rowId = (row["id"] as? Number)?.toInt() ?: (row["id"] as? String)?.toIntOrNull() ?: 0
                            Text(text = row["description"]?.toString() ?: row["type"]?.toString() ?: if (rowId > 0) "#$rowId" else "লেজার এন্ট্রি", style = MaterialTheme.typography.titleSmall)
                            Text(text = "পরিমাণ: ${row["amount"] ?: "-"}", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
        }
    }
}
