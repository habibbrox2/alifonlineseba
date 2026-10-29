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
import com.aliftools.admin.ui.components.design.StatusPill
import com.aliftools.admin.ui.format.Money
import com.aliftools.admin.ui.viewmodel.UsersViewModel

private val ROLES = listOf("user" to "ইউজার", "staff" to "স্টাফ", "admin" to "অ্যাডমিন")

@Composable
fun UsersScreen(
    onBack: () -> Unit,
    showBack: Boolean = true,
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
        showBack = showBack,
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
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = user.username,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                )
                StatusPill(user.status ?: "active")
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = listOfNotNull(
                        user.phone.takeIf { it.isNotBlank() },
                        roleLabel(user.role),
                    ).joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    text = Money.format(user.balance),
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                )
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
    val isTrashed = !user.deletedAt.isNullOrBlank()
    val isActive = user.status == "active"

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        title = { Text(user.username) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("মোবাইল: ${user.phone.ifBlank { "—" }}")
                Text("ব্যালেন্স: ${Money.format(user.balance)}")
                Text("রোল: ${roleLabel(user.role)}")
                Text("স্ট্যাটাস: ${if (isActive) "সক্রিয়" else "নিষ্ক্রিয়"}")
                if (isTrashed) Text("অবস্থা: ট্র্যাশে আছে", color = MaterialTheme.colorScheme.error)
            }
        },
        confirmButton = {
            Column(modifier = Modifier.fillMaxWidth()) {
                if (!isTrashed) {
                    TextButton(onClick = { onToggle(); onDismiss() }, enabled = !busy) {
                        Text(if (isActive) "নিষ্ক্রিয় করুন" else "সক্রিয় করুন")
                    }
                    TextButton(onClick = onChangeRole, enabled = !busy) {
                        Text("রোল পরিবর্তন")
                    }
                    TextButton(onClick = { onReset(); onDismiss() }, enabled = !busy) {
                        Text("পাসওয়ার্ড রিসেট")
                    }
                    TextButton(onClick = { onDelete(); onDismiss() }, enabled = !busy) {
                        Text("ট্র্যাশে পাঠান", color = MaterialTheme.colorScheme.error)
                    }
                } else {
                    TextButton(onClick = { onRestore(); onDismiss() }, enabled = !busy) {
                        Text("ফিরিয়ে আনুন")
                    }
                }
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("বাতিল") }
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
        title = { Text("রোল বেছে নিন") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ROLES.forEach { (value, label) ->
                    TextButton(
                        onClick = { picked = value },
                        modifier = Modifier.fillMaxWidth(),
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
