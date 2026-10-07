package com.aliftools.admin.ui.screens.dashboard

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.Button
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
import com.aliftools.admin.ui.viewmodel.DashboardUiState
import com.aliftools.admin.ui.viewmodel.DashboardViewModel

@Composable
fun DashboardScreen(
    onNavigateToUsers: () -> Unit,
    onNavigateToOrders: () -> Unit,
    onNavigateToRecharges: () -> Unit,
    onNavigateToWithdraws: () -> Unit,
    onNavigateToSettings: () -> Unit,
    onNavigateToLogs: () -> Unit,
    onNavigateToNotifications: () -> Unit,
    onNavigateToStaff: () -> Unit,
    viewModel: DashboardViewModel = hiltViewModel()
) {
    val state by viewModel.state.collectAsState()

    when (state) {
        is DashboardUiState.Loading -> {
            Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator()
            }
        }
        is DashboardUiState.Error -> {
            Column(
                modifier = Modifier.fillMaxSize(),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center
            ) {
                Text(text = (state as DashboardUiState.Error).message, color = MaterialTheme.colorScheme.error)
                Button(onClick = { viewModel.load() }, modifier = Modifier.padding(top = 8.dp)) {
                    Text("পুনরায় চেষ্টা করুন")
                }
            }
        }
        is DashboardUiState.Success -> {
            val data = (state as DashboardUiState.Success).data
            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                item {
                    Text(text = "ড্যাশবোর্ড", style = MaterialTheme.typography.headlineMedium)
                }
                item {
                    MetricGrid(
                        userCount = data.userCount,
                        openOrders = data.openOrders,
                        unresolvedCount = data.unresolvedCount,
                        unresolvedAmount = data.unresolvedAmount
                    )
                }
                item {
                    QuickActions(
                        onUsers = onNavigateToUsers,
                        onOrders = onNavigateToOrders,
                        onRecharges = onNavigateToRecharges,
                        onWithdraws = onNavigateToWithdraws,
                        onSettings = onNavigateToSettings,
                        onLogs = onNavigateToLogs,
                        onNotifications = onNavigateToNotifications,
                        onStaff = onNavigateToStaff
                    )
                }
            }
        }
        else -> { /* Idle */ }
    }
}

@Composable
private fun MetricGrid(
    userCount: Int,
    openOrders: Int,
    unresolvedCount: Int,
    unresolvedAmount: String
) {
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        MetricCard(title = "মোট ইউজার", value = userCount.toString(), modifier = Modifier.weight(1f))
        MetricCard(title = "অ্যাক্টিভ অর্ডার", value = openOrders.toString(), modifier = Modifier.weight(1f))
    }
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        MetricCard(title = "পেন্ডিং রিচার্জ", value = unresolvedCount.toString(), modifier = Modifier.weight(1f))
        MetricCard(title = "অনরক্ষিত Amount", value = unresolvedAmount, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun MetricCard(title: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier = modifier) {
        Column(modifier = Modifier.padding(12.dp)) {
            Text(text = title, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(text = value, style = MaterialTheme.typography.headlineSmall)
        }
    }
}

@Composable
private fun QuickActions(
    onUsers: () -> Unit,
    onOrders: () -> Unit,
    onRecharges: () -> Unit,
    onWithdraws: () -> Unit,
    onSettings: () -> Unit,
    onLogs: () -> Unit,
    onNotifications: () -> Unit,
    onStaff: () -> Unit
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = onUsers, modifier = Modifier.weight(1f)) { Text("ইউজার") }
            Button(onClick = onOrders, modifier = Modifier.weight(1f)) { Text("অর্ডার") }
        }
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = onRecharges, modifier = Modifier.weight(1f)) { Text("রিচার্জ") }
            Button(onClick = onWithdraws, modifier = Modifier.weight(1f)) { Text("উত্তোলন") }
        }
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = onNotifications, modifier = Modifier.weight(1f)) { Text("নোটিফিকেশন") }
            Button(onClick = onLogs, modifier = Modifier.weight(1f)) { Text("লগ") }
        }
        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = onSettings, modifier = Modifier.weight(1f)) { Text("সেটিংস") }
            Button(onClick = onStaff, modifier = Modifier.weight(1f)) { Text("স্টাফ") }
        }
    }
}
