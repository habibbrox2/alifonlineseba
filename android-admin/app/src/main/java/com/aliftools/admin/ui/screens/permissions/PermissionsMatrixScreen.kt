package com.aliftools.admin.ui.screens.permissions

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
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
import com.aliftools.admin.ui.viewmodel.PermissionsMatrixViewModel

@Composable
fun PermissionsMatrixScreen(
    onBack: () -> Unit,
    viewModel: PermissionsMatrixViewModel = hiltViewModel(),
) {
    val data by viewModel.data.collectAsState()
    val loading by viewModel.loading.collectAsState()
    val snack by viewModel.snack.collectAsState()

    AdminScaffold(
        title = "পারমিশন ম্যাট্রিক্স",
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
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(12.dp)) {
                    Text(text = data["message"]?.toString() ?: "পারমিশন ম্যাট্রিক্স", style = MaterialTheme.typography.titleMedium)
                }
            }
        }
    }
}
