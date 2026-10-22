package com.aliftools.admin.ui.screens.users

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.data.model.User
import com.aliftools.admin.ui.components.PagedListView
import com.aliftools.admin.ui.viewmodel.UsersViewModel

private val ROLES = listOf("user" to "ইউজার", "staff" to "স্টাফ", "admin" to "অ্যাডমিন")

@Composable
fun UsersScreen(
    onBack: () -> Unit,
    viewModel: UsersViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    val temporaryPassword by viewModel.temporaryPassword.collectAsState()
    val created by viewModel.created.collectAsState()

    var selected by remember { mutableStateOf<User?>(null) }
    var rolePicker by remember { mutableStateOf<User?>(null) }
    var showCreate by remember { mutableStateOf(false) }

    // A successful create closes the dialog; a failed one keeps it open so
    // the fields the user typed survive the error snackbar.
    LaunchedEffect(created) {
        if (created) {
            showCreate = false
            viewModel.dismissCreated()
        }
    }

    PagedListView(
        title = "ইউজার",
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
        filters = listOf("" to "সক্রিয়", "all" to "সব", "only" to "ট্র্যাশ"),
        actions = {
            IconButton(onClick = { showCreate = true }) {
                Icon(
                    Icons.Default.PersonAdd,
                    contentDescription = "নতুন ইউজার তৈরি",
                )
            }
        },
        itemKey = { it.id },
        // Accessible: each row is a selectable item with full user info
    ) { user ->
        UserRow(user = user, onClick = { selected = user })
    }

    selected?.let { user ->
        UserActionsDialog(
            user = user,
            busy = busy,
            onDismiss = { selected = null },
            onToggle = { viewModel.toggleStatus(user) },
            onChangeRole = {
                rolePicker = user
                selected = null
            },
            onReset = { viewModel.resetPassword(user) },
            onDelete = { viewModel.deleteUser(user) },
            onRestore = { viewModel.restoreUser(user) },
        )
    }

    rolePicker?.let { user ->
        RoleDialog(
            user = user,
            busy = busy,
            onDismiss = { rolePicker = null },
            onPick = { role ->
                viewModel.changeRole(user, role)
                rolePicker = null
            },
        )
    }

    temporaryPassword?.let { password ->
        TemporaryPasswordDialog(
            password = password,
            onDismiss = viewModel::dismissTemporaryPassword,
        )
    }

    if (showCreate) {
        CreateUserDialog(
            busy = busy,
            onDismiss = { showCreate = false },
            onCreate = { username, phone, password, role, email ->
                viewModel.createUser(username, phone, password, role, email)
            },
        )
    }
}

@Composable
private fun UserRow(user: User, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth()
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = user.fullName?.takeIf { it.isNotBlank() } ?: user.username,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
                Text(
                    text = roleLabel(user.role),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.primary,
                )
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    text = "@${user.username} · ${user.phone}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    text = "৳${user.balance}",
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
            val flags = buildList {
                if (user.status != null && user.status != "active") add("ব্লকড" to MaterialTheme.colorScheme.error)
                if (user.deletedAt != null) add("ট্র্যাশে" to MaterialTheme.colorScheme.tertiary)
            }
            if (flags.isNotEmpty()) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    flags.forEach { (label, color) ->
                        Text(
                            text = label,
                            style = MaterialTheme.typography.labelSmall,
                            color = color,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun UserActionsDialog(
    user: User,
    busy: Boolean,
    onDismiss: () -> Unit,
    onToggle: () -> Unit,
    onChangeRole: () -> Unit,
    onReset: () -> Unit,
    onDelete: () -> Unit,
    onRestore: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(user.username) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text("ফোন: ${user.phone}", style = MaterialTheme.typography.bodyMedium)
                Text("ইমেইল: ${user.email ?: "-"}", style = MaterialTheme.typography.bodyMedium)
                Text("রোল: ${roleLabel(user.role)}", style = MaterialTheme.typography.bodyMedium)
                Text("ব্যালেন্স: ৳${user.balance}", style = MaterialTheme.typography.bodyMedium)

                Column(
                    modifier = Modifier.padding(top = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(0.dp),
                ) {
                    TextButton(onClick = onToggle, enabled = !busy) {
                        Text(if (user.status == "active") "ব্লক করুন" else "আনব্লক করুন")
                    }
                    TextButton(onClick = onChangeRole, enabled = !busy) {
                        Text("রোল পরিবর্তন")
                    }
                    TextButton(onClick = onReset, enabled = !busy) {
                        Text("পাসওয়ার্ড রিসেট")
                    }
                    if (user.deletedAt != null) {
                        TextButton(onClick = onRestore, enabled = !busy) {
                            Text("ফিরিয়ে আনুন")
                        }
                    } else {
                        TextButton(
                            onClick = onDelete,
                            enabled = !busy,
                            colors = androidx.compose.material3.ButtonDefaults.textButtonColors(
                                contentColor = MaterialTheme.colorScheme.error,
                            ),
                        ) {
                            Text("ট্র্যাশে পাঠান")
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) { Text("বন্ধ") }
        },
    )
}

@Composable
private fun RoleDialog(
    user: User,
    busy: Boolean,
    onDismiss: () -> Unit,
    onPick: (String) -> Unit,
) {
    var picked by remember(user.id) { mutableStateOf(user.role) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("রোল — ${user.username}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                ROLES.forEach { (value, label) ->
                    TextButton(
                        onClick = { picked = value },
                        enabled = !busy,
                    ) {
                        Text(
                            text = (if (picked == value) "● " else "○ ") + label,
                            color = if (picked == value) {
                                MaterialTheme.colorScheme.primary
                            } else {
                                MaterialTheme.colorScheme.onSurface
                            },
                        )
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { onPick(picked) }, enabled = !busy) { Text("সংরক্ষণ") }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("বাতিল") }
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

@Composable
private fun CreateUserDialog(
    busy: Boolean,
    onDismiss: () -> Unit,
    onCreate: (username: String, phone: String, password: String, role: String, email: String) -> Unit,
) {
    var username by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var role by remember { mutableStateOf("user") }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text("নতুন ইউজার") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = username,
                    onValueChange = { username = it },
                    label = { Text("ইউজারনেম") },
                    singleLine = true,
                    enabled = !busy,
                )
                OutlinedTextField(
                    value = phone,
                    onValueChange = { phone = it },
                    label = { Text("মোবাইল (01XXXXXXXXX)") },
                    singleLine = true,
                    enabled = !busy,
                )
                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text("পাসওয়ার্ড (৮+ অক্ষর)") },
                    singleLine = true,
                    enabled = !busy,
                    visualTransformation = PasswordVisualTransformation(),
                )
                OutlinedTextField(
                    value = email,
                    onValueChange = { email = it },
                    label = { Text("ইমেইল (ঐচ্ছিক)") },
                    singleLine = true,
                    enabled = !busy,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ROLES.forEach { (value, label) ->
                        TextButton(onClick = { role = value }, enabled = !busy) {
                            Text(
                                text = (if (role == value) "● " else "○ ") + label,
                                color = if (role == value) {
                                    MaterialTheme.colorScheme.primary
                                } else {
                                    MaterialTheme.colorScheme.onSurface
                                },
                            )
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onCreate(username.trim(), phone.trim(), password, role, email.trim()) },
                enabled = !busy && username.isNotBlank() && phone.isNotBlank() && password.isNotBlank(),
            ) {
                Text("তৈরি করুন")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, enabled = !busy) { Text("বাতিল") }
        },
    )
}

private fun roleLabel(role: String): String = ROLES.firstOrNull { it.first == role }?.second ?: role
