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
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
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

    var showCreate by remember { mutableStateOf(false) }
    var editRow by remember { mutableStateOf<Map<String, Any?>?>(null) }

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
            // Create card
            if (showCreate) {
                CreateServiceCard(
                    busy = busy,
                    onDismiss = { showCreate = false },
                    onCreate = { name, slug, categoryId, price, unit, description, sortOrder, status ->
                        viewModel.create(name, slug, categoryId, price, unit, description, sortOrder, status)
                        showCreate = false
                    },
                )
            } else {
                IconButton(onClick = { showCreate = true }, enabled = !busy) {
                    Icon(
                        imageVector = Icons.Default.Add,
                        contentDescription = "নতুন সার্ভিস যোগ করুন",
                    )
                }
            }

            if (loading) {
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
                    CircularProgressIndicator()
                }
            }

            LazyColumn(modifier = Modifier.weight(1f).fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(rows) { row ->
                    val id = (row["id"] as? Number)?.toInt() ?: (row["id"] as? String)?.toIntOrNull() ?: 0
                    val title = row["name"]?.toString() ?: ""
                    val status = row["status"]?.toString() ?: ""
                    val price = row["price"]?.toString() ?: ""
                    val unit = row["unit"]?.toString() ?: ""
                    val isActive = status == "active"

                    ServiceRow(
                        id = id,
                        title = title,
                        price = price,
                        unit = unit,
                        isActive = isActive,
                        busy = busy,
                        onToggle = { viewModel.toggle(id) },
                        onEdit = {
                            editRow = row
                        },
                        onDelete = {
                            viewModel.delete(id)
                        },
                    )
                }
            }

            editRow?.let { row ->
                val id = (row["id"] as? Number)?.toInt() ?: (row["id"] as? String)?.toIntOrNull() ?: 0
                EditServiceDialog(
                    row = row,
                    id = id,
                    busy = busy,
                    onDismiss = { editRow = null },
                    onSave = { name, slug, categoryId, price, unit, description, sortOrder, status ->
                        viewModel.edit(id, name, slug, categoryId, price, unit, description, sortOrder, status)
                        editRow = null
                    },
                )
            }
        }
    }
}

@Composable
private fun ServiceRow(
    id: Int,
    title: String,
    price: String,
    unit: String,
    isActive: Boolean,
    busy: Boolean,
    onToggle: () -> Unit,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(12.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column {
                Text(text = title, style = MaterialTheme.typography.titleSmall)
                val priceText = if (price.isNotBlank()) "$price${unit.ifBlank { "" }}" else ""
                Text(
                    text = priceText.ifBlank { "মূল্য সেট করা নেই" },
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Row {
                Switch(
                    checked = isActive,
                    onCheckedChange = { if (!busy) onToggle() },
                    enabled = !busy,
                )
                IconButton(
                    onClick = { if (!busy) onEdit() },
                    enabled = !busy,
                ) {
                    Icon(
                        imageVector = Icons.Default.Edit,
                        contentDescription = "সম্পাদনা করুন",
                    )
                }
                IconButton(
                    onClick = { if (!busy) onDelete() },
                    enabled = !busy,
                ) {
                    Icon(
                        imageVector = Icons.Default.Delete,
                        contentDescription = "মুছুন",
                    )
                }
            }
        }
    }
}

@Composable
private fun CreateServiceCard(
    busy: Boolean,
    onDismiss: () -> Unit,
    onCreate: (
        name: String,
        slug: String,
        categoryId: Int?,
        price: String?,
        unit: String?,
        description: String?,
        sortOrder: Int?,
        status: String?,
    ) -> Unit,
) {
    var name by remember { mutableStateOf("") }
    var slug by remember { mutableStateOf("") }
    var categoryIdField by remember { mutableStateOf("") }
    var price by remember { mutableStateOf("") }
    var unit by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var sortOrder by remember { mutableStateOf("") }
    var status by remember { mutableStateOf("active") }

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(text = "নতুন সার্ভিস যোগ করুন", style = MaterialTheme.typography.titleMedium)

            OutlinedTextField(
                value = name,
                onValueChange = { name = it },
                label = { Text("নাম") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                enabled = !busy,
            )

            OutlinedTextField(
                value = slug,
                onValueChange = { slug = it },
                label = { Text("স্লাগ (যেমন: mobile-recharge)") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                enabled = !busy,
            )

            OutlinedTextField(
                value = categoryIdField,
                onValueChange = { categoryIdField = it },
                label = { Text("ক্যাটাগরি আইডি (ঐচ্ছিক)") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                enabled = !busy,
            )

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                OutlinedTextField(
                    value = price,
                    onValueChange = { price = it },
                    label = { Text("মূল্য") },
                    modifier = Modifier.weight(1f),
                    singleLine = true,
                    enabled = !busy,
                )
                OutlinedTextField(
                    value = unit,
                    onValueChange = { unit = it },
                    label = { Text("ইউনিট") },
                    modifier = Modifier.weight(1f),
                    singleLine = true,
                    enabled = !busy,
                )
            }

            OutlinedTextField(
                value = description,
                onValueChange = { description = it },
                label = { Text("বিবরণ (ঐচ্ছিক)") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                enabled = !busy,
            )

            OutlinedTextField(
                value = sortOrder,
                onValueChange = { sortOrder = it },
                label = { Text("সর্ট অর্ডার (সংখ্যা)") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                enabled = !busy,
            )

            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = "স্ট্যাটাস: ${if (status == "active") "সক্রিয়" else "নিষ্ক্রিয়"}",
                    style = MaterialTheme.typography.bodyMedium,
                )
                Switch(
                    checked = status == "active",
                    onCheckedChange = { status = if (it) "active" else "inactive" },
                    enabled = !busy,
                )
            }

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.End,
            ) {
                TextButton(onClick = { onDismiss() }, enabled = !busy) {
                    Text("বাতিল")
                }
                androidx.compose.material3.Button(
                    onClick = {
                        if (name.isNotBlank() && slug.isNotBlank()) {
                            val catId = if (categoryIdField.isNotBlank()) categoryIdField.toIntOrNull() else null
                            val sort = if (sortOrder.isNotBlank()) sortOrder.toIntOrNull() else null
                            onCreate(
                                name.trim(),
                                slug.trim(),
                                catId,
                                price.ifBlank { null },
                                unit.ifBlank { null },
                                description.ifBlank { null },
                                sort,
                                status,
                            )
                        }
                    },
                    enabled = !busy && name.isNotBlank() && slug.isNotBlank(),
                ) {
                    Text("তৈরি করুন")
                }
            }
        }
    }
}

@Composable
private fun EditServiceDialog(
    row: Map<String, Any?>,
    id: Int,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSave: (
        name: String,
        slug: String,
        categoryId: Int?,
        price: String?,
        unit: String?,
        description: String?,
        sortOrder: Int?,
        status: String?,
    ) -> Unit,
) {
    var name by remember(row) { mutableStateOf(row["name"]?.toString() ?: "") }
    var slug by remember(row) { mutableStateOf(row["slug"]?.toString() ?: "") }
    var price by remember(row) { mutableStateOf(row["price"]?.toString() ?: "") }
    var unit by remember(row) { mutableStateOf(row["unit"]?.toString() ?: "") }
    var description by remember(row) { mutableStateOf(row["description"]?.toString() ?: "") }
    var sortOrder by remember(row) { mutableStateOf(row["sort_order"]?.toString() ?: "") }
    var status by remember(row) { mutableStateOf(row["status"]?.toString() ?: "active") }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("সার্ভিস সম্পাদনা করুন") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("নাম") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    enabled = !busy,
                )
                OutlinedTextField(
                    value = slug,
                    onValueChange = { slug = it },
                    label = { Text("স্লাগ") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    enabled = !busy,
                )
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    OutlinedTextField(
                        value = price,
                        onValueChange = { price = it },
                        label = { Text("মূল্য") },
                        modifier = Modifier.weight(1f),
                        singleLine = true,
                        enabled = !busy,
                    )
                    OutlinedTextField(
                        value = unit,
                        onValueChange = { unit = it },
                        label = { Text("ইউনিট") },
                        modifier = Modifier.weight(1f),
                        singleLine = true,
                        enabled = !busy,
                    )
                }
                OutlinedTextField(
                    value = description,
                    onValueChange = { description = it },
                    label = { Text("বিবরণ (ঐচ্ছিক)") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    enabled = !busy,
                )
                OutlinedTextField(
                    value = sortOrder,
                    onValueChange = { sortOrder = it },
                    label = { Text("সর্ট অর্ডার (সংখ্যা)") },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    enabled = !busy,
                )
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        text = "স্ট্যাটাস: ${if (status == "active") "সক্রিয়" else "নিষ্ক্রিয়"}",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                    Switch(
                        checked = status == "active",
                        onCheckedChange = { status = if (it) "active" else "inactive" },
                        enabled = !busy,
                    )
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    if (name.isNotBlank() && slug.isNotBlank()) {
                        val catId = row["category_id"] as? Int
                        val sort = if (sortOrder.isNotBlank()) sortOrder.toIntOrNull() else null
                        onSave(
                            name.trim(),
                            slug.trim(),
                            catId,
                            price.ifBlank { null },
                            unit.ifBlank { null },
                            description.ifBlank { null },
                            sort,
                            status,
                        )
                    }
                },
                enabled = !busy && name.isNotBlank() && slug.isNotBlank(),
            ) { Text("সংরক্ষণ করুন") }
        },
        dismissButton = {
            TextButton(onClick = { if (!busy) onDismiss() }) { Text("বাতিল") }
        },
    )
}
