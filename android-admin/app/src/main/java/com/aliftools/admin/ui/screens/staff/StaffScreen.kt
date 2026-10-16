package com.aliftools.admin.ui.screens.staff

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.TextButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.data.model.User
import com.aliftools.admin.ui.components.PagedListView
import com.aliftools.admin.ui.viewmodel.StaffViewModel

private val ROLE_LABELS = mapOf(
    "superadmin" to "সুপারঅ্যাডমিন",
    "admin" to "অ্যাডমিন",
    "staff" to "স্টাফ",
)

@Composable
fun StaffScreen(
    onBack: () -> Unit,
    viewModel: StaffViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val temporaryPassword by viewModel.temporaryPassword.collectAsState()

    var selected by remember { mutableStateOf<User?>(null) }

    PagedListView(
        title = "স্টাফ",
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
        itemKey = { it.id },
    ) { member ->
        StaffRow(member = member, onClick = { selected = member })
    }

    selected?.let { user ->
        StaffActionsDialog(
            user = user,
            busy = busy,
            onDismiss = { selected = null },
            onReset = { viewModel.resetPassword(user) },
        )
    }

    temporaryPassword?.let { password ->
        TemporaryPasswordDialog(
            password = password,
            onDismiss = viewModel::dismissTemporaryPassword,
        )
    }
}

@Composable
private fun StaffRow(member: User, onClick: () -> Unit) {
    Card(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    text = member.fullName?.takeIf { it.isNotBlank() } ?: member.username,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                Text(
                    text = ROLE_LABELS[member.role] ?: member.role,
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.primary,
                )
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    text = "@${member.username} · ${member.phone}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    text = if (member.status == "active" || member.status == null) {
                        "সক্রিয়"
                    } else {
                        "ব্লকড"
                    },
                    style = MaterialTheme.typography.labelSmall,
                    color = if (member.status == "active" || member.status == null) {
                        MaterialTheme.colorScheme.primary
                    } else {
                        MaterialTheme.colorScheme.error
                    },
                )
            }
        }
    }
}

@Composable
private fun StaffActionsDialog(
    user: User,
    busy: Boolean,
    onDismiss: () -> Unit,
    onReset: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(user.username) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text("ফোন: ${user.phone}", style = MaterialTheme.typography.bodyMedium)
                Text("রোল: ${ROLE_LABELS[user.role] ?: user.role}", style = MaterialTheme.typography.bodyMedium)
                Text(
                    text = "পাসওয়ার্ড রিসেট করলে ইউজারকে নতুন অস্থায়ী পাসওয়ার্ড দেওয়া হবে।",
                    style = MaterialTheme.typography.bodyMedium,
                )
                TextButton(onClick = onReset, enabled = !busy) {
                    Text("পাসওয়ার্ড রিসেট")
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) { Text("বন্ধ") }
        },
    )
}

@Composable
private fun TemporaryPasswordDialog(password: String, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("অস্থায়ী পাসওয়ার্ড") },
        text = {
            Column {
                Text(
                    text = "ইউজারকে এই পাসওয়ার্ডটি দিন — লগইনের পর পরিবর্তন করতে হবে।",
                    style = MaterialTheme.typography.bodyMedium,
                )
                Text(
                    text = password,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(top = 8.dp),
                )
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) { Text("ঠিক আছে") }
        },
    )
}
