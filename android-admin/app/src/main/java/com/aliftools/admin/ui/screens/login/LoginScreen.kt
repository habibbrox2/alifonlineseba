package com.aliftools.admin.ui.screens.login

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
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
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import com.aliftools.admin.ui.auth.FirebasePhoneSignIn
import com.aliftools.admin.ui.auth.FirebasePhoneSignIn.Companion.findActivity
import com.aliftools.admin.ui.viewmodel.LoginUiState
import com.aliftools.admin.ui.viewmodel.LoginViewModel

/**
 * Login form restyled with emerald gradient and Bengali forms.
 * Three ways in, all landing on the same success path.
 */
@Composable
fun LoginScreen(
    onLoginSuccess: (String) -> Unit,
    viewModel: LoginViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsState()
    val remembered by viewModel.rememberedIdentifier.collectAsState()
    val rememberMe by viewModel.rememberMe.collectAsState()

    var identifier by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var passwordVisible by remember { mutableStateOf(false) }

    // Firebase phone sign-in. Absent from builds with no google-services.json,
    // in which case the whole block below never renders a control.
    val context = LocalContext.current
    val phoneSignIn = remember(context) { context.findActivity()?.let { FirebasePhoneSignIn(it) } }
    val firebaseAvailable = remember(phoneSignIn) { phoneSignIn?.isAvailable() == true }
    var otpDialog by remember { mutableStateOf(false) }
    var otpCode by remember { mutableStateOf("") }
    var phoneBusy by remember { mutableStateOf(false) }
    var phoneError by remember { mutableStateOf<String?>(null) }

    fun beginPhoneSignIn() {
        val helper = phoneSignIn ?: return
        phoneError = null
        val raw = identifier.trim()
        val digits = raw.removePrefix("+").filter { it.isDigit() }
        if (digits.length < 9 || digits.length > 15 || digits.length != raw.removePrefix("+").length) {
            phoneError = "ফোন সাইন ইন করার জন্য সঠিক ফোন নম্বর দিন (যেমন 01712345678)।"
            return
        }
        val e164 = if (raw.startsWith("+")) raw else "+880${raw.removePrefix("0")}".trimEnd('+')
        phoneBusy = true
        helper.start(
            phoneNumber = e164,
            onToken = { token ->
                phoneBusy = false
                viewModel.loginWithFirebase(token, provider = "phone")
            },
            onCodeSent = { phoneBusy = false; otpDialog = true; otpCode = "" },
            onError = { msg -> phoneBusy = false; phoneError = msg },
        )
    }

    // Seed the identifier once from what "remember me" stored.
    LaunchedEffect(remembered) {
        if (!remembered.isNullOrBlank() && identifier.isBlank()) {
            identifier = remembered.orEmpty()
        }
    }

    LaunchedEffect(state) {
        if (state is LoginUiState.Success) {
            onLoginSuccess((state as LoginUiState.Success).role)
        }
    }

    val busy = state is LoginUiState.Loading
    val emerald = MaterialTheme.colorScheme.primary
    val emeraldLight = emerald.copy(alpha = 0.08f)

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    colors = listOf(emeraldLight, MaterialTheme.colorScheme.background),
                )
            ),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .statusBarsPadding()
                .navigationBarsPadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.height(48.dp))
            BrandMark()
            Spacer(Modifier.height(16.dp))
            Text(
                text = "Alif Tools Admin",
                style = MaterialTheme.typography.headlineMedium,
                color = emerald,
            )
            Text(
                text = "অ্যাডমিন কনসোলে সাইন ইন করুন",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = 4.dp),
            )
            Spacer(Modifier.height(32.dp))

            // Login card with emerald accent
            Surface(
                shape = MaterialTheme.shapes.large,
                color = MaterialTheme.colorScheme.surface,
                tonalElevation = 4.dp,
                modifier = Modifier.fillMaxWidth(),
                shadowElevation = 8.dp,
            ) {
                Column(modifier = Modifier.padding(24.dp)) {
                    // Username field
                    OutlinedTextField(
                        value = identifier,
                        onValueChange = { identifier = it },
                        label = { Text("ইউজারনেম / ফোন") },
                        leadingIcon = { Icon(Icons.Default.Person, contentDescription = null) },
                        modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Text,
                            imeAction = ImeAction.Next,
                        ),
                        singleLine = true,
                        shape = MaterialTheme.shapes.medium,
                        enabled = !busy,
                    )
                    Spacer(Modifier.height(16.dp))

                    // Password field
                    OutlinedTextField(
                        value = password,
                        onValueChange = { password = it },
                        label = { Text("পাসওয়ার্ড") },
                        leadingIcon = { Icon(Icons.Default.Lock, contentDescription = null) },
                        trailingIcon = {
                            IconButton(onClick = { passwordVisible = !passwordVisible }) {
                                Icon(
                                    imageVector = if (passwordVisible) {
                                        Icons.Default.VisibilityOff
                                    } else {
                                        Icons.Default.Visibility
                                    },
                                    contentDescription = if (passwordVisible) {
                                        "পাসওয়ার্ড লুকান"
                                    } else {
                                        "পাসওয়ার্ড দেখুন"
                                    },
                                )
                            }
                        },
                        visualTransformation = if (passwordVisible) {
                            VisualTransformation.None
                        } else {
                            PasswordVisualTransformation()
                        },
                        modifier = Modifier.fillMaxWidth(),
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Password,
                            imeAction = ImeAction.Done,
                        ),
                        singleLine = true,
                        shape = MaterialTheme.shapes.medium,
                        enabled = !busy,
                    )
                    Spacer(Modifier.height(24.dp))

                    // Remember me toggle
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(top = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Switch(
                            checked = rememberMe,
                            onCheckedChange = viewModel::setRememberMe,
                            enabled = !busy,
                        )
                        Spacer(Modifier.width(10.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text(
                                text = "আমাকে মনে রাখুন",
                                style = MaterialTheme.typography.labelLarge,
                            )
                            Text(
                                text = "শুধু ইউজারনেম সেভ হবে, পাসওয়ার্ড নয়",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }

                    Spacer(Modifier.height(24.dp))

                    // Login button with emerald background
                    Button(
                        onClick = { viewModel.login(identifier, password, rememberMe) },
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(48.dp),
                        enabled = !busy,
                    ) {
                        if (busy) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(20.dp),
                                strokeWidth = 2.dp,
                                color = MaterialTheme.colorScheme.onPrimary,
                            )
                            Spacer(Modifier.width(10.dp))
                            Text("লগইন হচ্ছে...")
                        } else {
                            Text("লগইন করুন", style = MaterialTheme.typography.labelLarge)
                        }
                    }

                    Spacer(Modifier.height(16.dp))

                    if (state is LoginUiState.Error) {
                        Text(
                            text = (state as LoginUiState.Error).message,
                            color = MaterialTheme.colorScheme.error,
                            style = MaterialTheme.typography.bodyMedium,
                            modifier = Modifier.padding(top = 4.dp),
                        )
                    }
                }
            }

            if (firebaseAvailable) {
                phoneError?.let {
                    Text(
                        text = it,
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.padding(top = 12.dp),
                    )
                }
                Spacer(Modifier.height(24.dp))
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    HorizontalDivider(modifier = Modifier.weight(1f))
                    Text(
                        text = "অথবা",
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(horizontal = 12.dp),
                    )
                    HorizontalDivider(modifier = Modifier.weight(1f))
                }
                Spacer(Modifier.height(16.dp))
                FirebasePhoneButton(
                    enabled = !busy && !phoneBusy,
                    loading = phoneBusy,
                    onClick = ::beginPhoneSignIn,
                )
            }

            Spacer(Modifier.height(32.dp))
        }
    }

    // SMS fallback: shown only when Play Services could not auto-verify.
    if (otpDialog) {
        OtpDialog(
            code = otpCode,
            onCodeChange = { otpCode = it },
            onConfirm = {
                otpDialog = false
                phoneBusy = true
                phoneSignIn?.signInWithCode(
                    code = otpCode.trim(),
                    onToken = { token ->
                        phoneBusy = false
                        viewModel.loginWithFirebase(token, provider = "phone")
                    },
                    onError = { msg -> phoneBusy = false; phoneError = msg }
                )
            },
            onDismiss = { otpDialog = false; phoneSignIn?.reset() },
        )
    }
}

/** The app mark: a tinted circle carrying the initial, so the header is not bare text. */
@Composable
private fun BrandMark() {
    Box(
        modifier = Modifier
            .size(72.dp)
            .background(MaterialTheme.colorScheme.primary.copy(alpha = 0.14f), CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Box(
            modifier = Modifier
                .size(40.dp)
                .background(MaterialTheme.colorScheme.primary, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                text = "A",
                style = MaterialTheme.typography.headlineSmall,
                color = MaterialTheme.colorScheme.onPrimary,
            )
        }
    }
}

@Composable
private fun FirebasePhoneButton(
    enabled: Boolean,
    loading: Boolean,
    onClick: () -> Unit,
) {
    OutlinedButton(
        onClick = onClick,
        enabled = enabled,
        modifier = Modifier
            .fillMaxWidth()
            .height(48.dp),
        shape = MaterialTheme.shapes.medium,
    ) {
        if (loading) {
            CircularProgressIndicator(
                modifier = Modifier.size(18.dp),
                strokeWidth = 2.dp,
                color = MaterialTheme.colorScheme.primary,
            )
            Spacer(Modifier.width(10.dp))
            Text("কোড পাঠানো হচ্ছে...")
        } else {
            Icon(Icons.Default.Phone, contentDescription = null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text("ফোন নম্বর দিয়ে লগইন", style = MaterialTheme.typography.labelLarge)
        }
    }
}

/** SMS fallback prompt. Six digits, one action, and a way out. */
@Composable
private fun OtpDialog(
    code: String,
    onCodeChange: (String) -> Unit,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("যাচাইকরণ কোড") },
        text = {
            Column {
                Text(
                    text = "আপনার ফোনে পাঠানো ৬-সংখ্যার কোডটি লিখুন।",
                    style = MaterialTheme.typography.bodyMedium,
                )
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    value = code,
                    onValueChange = { input ->
                        onCodeChange(input.filter { it.isDigit() }.take(6))
                    },
                    label = { Text("কোড") },
                    keyboardOptions = KeyboardOptions(
                        keyboardType = KeyboardType.NumberPassword,
                        imeAction = ImeAction.Done,
                    ),
                    singleLine = true,
                    shape = MaterialTheme.shapes.medium,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = onConfirm,
                enabled = code.length == 6,
            ) {
                Text("যাচাই করুন")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text("বাতিল")
            }
        },
        shape = MaterialTheme.shapes.large,
    )
}
