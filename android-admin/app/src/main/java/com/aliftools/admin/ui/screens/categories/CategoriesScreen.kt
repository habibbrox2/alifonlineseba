package com.aliftools.admin.ui.screens.categories

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.ToggleOff
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.ui.components.AdminScaffold
import com.aliftools.admin.ui.viewmodel.CategoriesViewModel

@Composable
fun CategoriesScreen(
    onBack: () -> Unit,
    viewModel: CategoriesViewModel = hiltViewModel(),
) {
    val rows by viewModel.rows.collectAsState()
    val loading by viewModel.loading.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    var name by remember { mutableStateOf("") }
    var slug by remember { mutableStateOf("") }

    AdminScaffold(
        title = "ক্যাটাগরি",
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
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(text = "নতুন ক্যাটাগরি যোগ করুন", style = MaterialTheme.typography.titleMedium)
                    OutlinedTextField(
                        value = name,
                        onValueChange = { name = it },
                        label = { Text("নাম") },
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        value = slug,
                        onValueChange = { slug = it },
                        label = { Text("স্লাগ") },
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Button(
                        onClick = {
                            if (name.isNotBlank()) {
                                viewModel.create(name, slug.ifBlank { name.lowercase().replace(" ", "-") })
                                name = ""
                                slug = ""
                            }
                        },
                        enabled = !busy,
                    ) {
                        Icon(Icons.Default.Add, contentDescription = null)
                        Text("যোগ করুন")
                    }
                }
            }

            if (loading) {
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
                    CircularProgressIndicator()
                }
            }

            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(rows) { row ->
                    val id = (row["id"] as? Number)?.toInt() ?: 0
                    val title = row["name"]?.toString() ?: ""
                    val status = row["status"]?.toString() ?: ""
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Row(
                            modifier = Modifier.fillMaxWidth().padding(12.dp),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Column {
                                Text(text = title, style = MaterialTheme.typography.titleSmall)
                                Text(text = "স্ট্যাটাস: $status", style = MaterialTheme.typography.bodySmall)
                            }
                            Row {
                                IconButton(onClick = { if (id > 0) viewModel.toggle(id) }, enabled = !busy) {
                                    Icon(Icons.Default.ToggleOff, contentDescription = "টগল")
                                }
                                IconButton(onClick = { if (id > 0) viewModel.delete(id) }, enabled = !busy) {
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
