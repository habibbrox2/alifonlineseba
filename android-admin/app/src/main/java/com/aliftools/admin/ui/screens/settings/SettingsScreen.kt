package com.aliftools.admin.ui.screens.settings

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Save
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.ui.components.AdminScaffold
import com.aliftools.admin.ui.viewmodel.SettingsViewModel
import com.aliftools.admin.util.BiometricHelper
import androidx.biometric.BiometricManager

@Composable
fun SettingsScreen(
    onBack: () -> Unit,
    viewModel: SettingsViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsState()
    val busy by viewModel.busy.collectAsState()
    val snack by viewModel.snack.collectAsState()
    var showConfirm by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val biometric = remember { BiometricHelper(context.applicationContext) }

    fun isSensitiveKey(key: String): Boolean {
        val k = key.lowercase()
        return listOf("secret", "key", "token", "password", "private", "merchant", "api_key", "apikey", "client_secret", "access_key").any { k.contains(it) }
    }

    fun requestSave() {
        val activity = context as? FragmentActivity
        if (activity != null && biometric.isBiometricAvailable() == BiometricManager.BIOMETRIC_SUCCESS) {
            biometric.showPrompt(activity, onSuccess = { viewModel.save() }, onFailure = {})
        } else {
            viewModel.save()
        }
    }

    AdminScaffold(
        title = "সেটিংস",
        onBack = onBack,
        snack = snack,
        onSnackShown = viewModel::clearSnack,
        actions = {
            IconButton(
                onClick = {
                    val loaded = uiState as? SettingsViewModel.UiState.Loaded
                    val hasSensitive = loaded?.values?.keys?.any { isSensitiveKey(it) } == true
                    if (hasSensitive) showConfirm = true else requestSave()
                },
                enabled = !busy && uiState is SettingsViewModel.UiState.Loaded
            ) {
                Icon(Icons.Default.Save, contentDescription = "সংরক্ষণ")
            }
        },
    ) { padding ->
        when (val state = uiState) {
            is SettingsViewModel.UiState.Loading -> {
                Column(
                    modifier = Modifier.fillMaxSize().padding(padding),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    CircularProgressIndicator()
                }
            }

            is SettingsViewModel.UiState.Error -> {
                Column(
                    modifier = Modifier.fillMaxSize().padding(padding),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    Text(
                        text = state.message,
                        color = MaterialTheme.colorScheme.error,
                    )
                    Button(onClick = viewModel::load, modifier = Modifier.padding(top = 12.dp)) {
                        Text("পুনরায় চেষ্টা করুন")
                    }
                }
            }

            is SettingsViewModel.UiState.Loaded -> {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(padding)
                        .verticalScroll(rememberScrollState())
                        .padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Text(
                        text = "সুপারঅ্যাডমিন সেটিংস",
                        style = MaterialTheme.typography.titleMedium,
                    )
                    Text(
                        text = "প্রতিটি ঘর ব্যাকএন্ডের কী অনুযায়ী — নতুন কী অ্যাপ আপডেট ছাড়াই এখানে দেখা যাবে।",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    if (busy) {
                        androidx.compose.material3.LinearProgressIndicator(
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                    state.values.toSortedMap().forEach { (key, value) ->
                        OutlinedTextField(
                            value = value,
                            onValueChange = { viewModel.onValueChange(key, it) },
                            label = { Text(prettyKey(key)) },
                            modifier = Modifier.fillMaxWidth(),
                            singleLine = true,
                            enabled = !busy,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Text),
                        )
                    }
                    Button(
                        onClick = {
                            val loaded = uiState as? SettingsViewModel.UiState.Loaded
                            val hasSensitive = loaded?.values?.keys?.any { isSensitiveKey(it) } == true
                            if (hasSensitive) showConfirm = true else requestSave()
                        },
                        modifier = Modifier.fillMaxWidth(),
                        enabled = !busy,
                    ) {
                        if (busy) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(16.dp),
                                strokeWidth = 2.dp,
                            )
                        } else {
                            Text("সংরক্ষণ করুন")
                        }
                    }
                }
            }
        }
    }

    if (showConfirm) {
        AlertDialog(
            onDismissRequest = { showConfirm = false },
            title = { Text("নিশ্চিত করুন") },
            text = { Text("সংবেদনশীল সেটিংস পরিবর্তন করছেন। আপনি কি নিশ্চিত যে এগুলো সঠিক?") },
            confirmButton = {
                TextButton(onClick = {
                    showConfirm = false
                    requestSave()
                }) { Text("সংরক্ষণ করুন") }
            },
            dismissButton = {
                TextButton(onClick = { showConfirm = false }) { Text("বাতিল") }
            }
        )
    }
}

/** `topup_min_amount` -> `Topup min amount`, so the raw key stays readable. */
private fun prettyKey(key: String): String =
    key.replace('_', ' ').replaceFirstChar { it.uppercaseChar() }
