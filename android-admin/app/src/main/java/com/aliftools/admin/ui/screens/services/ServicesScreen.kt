package com.aliftools.admin.ui.screens.services

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.ToggleOff
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import com.aliftools.admin.ui.viewmodel.ServicesViewModel

@Composable
fun ServicesScreen(
    onBack: () -> Unit,
    viewModel: ServicesViewModel = hiltViewModel(),
) {
    val rows by viewModel.rows.collectAsState()
    val loading by viewModel.loading.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()

    AdminScaffold(
        title = "সার্ভিস",
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

            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(rows) { row ->
                    val id = (row["id"] as? Number)?.toInt() ?: (row["id"] as? String)?.toIntOrNull() ?: 0
                    val title = row["name"]?.toString() ?: ""
                    val status = row["status"]?.toString() ?: ""
                    val price = row["price"]?.toString() ?: ""
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(12.dp),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Column {
                                Text(text = title, style = MaterialTheme.typography.titleSmall)
                                Text(
                                    text = "মূল্য: $price | স্ট্যাটাস: $status",
                                    style = MaterialTheme.typography.bodySmall
                                )
                            }
                            Row {
                                IconButton(
                                    onClick = { if (id > 0) viewModel.toggle(id) },
                                    enabled = !busy
                                ) {
                                    Icon(Icons.Default.ToggleOff, contentDescription = "টগল")
                                }
                                IconButton(
                                    onClick = { if (id > 0) viewModel.delete(id) },
                                    enabled = !busy
                                ) {
                                    Icon(Icons.Default.Delete, contentDescription = "মুছুন")
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
