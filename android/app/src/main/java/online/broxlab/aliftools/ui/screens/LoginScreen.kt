package online.broxlab.aliftools.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import online.broxlab.aliftools.data.LoginData
import online.broxlab.aliftools.data.LoginRequest
import online.broxlab.aliftools.data.Session
import online.broxlab.aliftools.data.AlifToolsApi
import online.broxlab.aliftools.data.TokenPair
import online.broxlab.aliftools.data.decodeJson

@Composable
fun LoginScreen(
    onLoggedIn: () -> Unit,
    api: AlifToolsApi,
    session: Session,
) {
    var identifier by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("All Seba", style = MaterialTheme.typography.headlineMedium)
        Spacer(Modifier.height(8.dp))
        Text("ডেমো প্ল্যাটফর্মে প্রবেশ করুন", style = MaterialTheme.typography.bodyMedium)

        Spacer(Modifier.height(24.dp))
        OutlinedTextField(
            value = identifier,
            onValueChange = { identifier = it },
            label = { Text("ইউজারনেম / মোবাইল নম্বর") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text("পাসওয়ার্ড") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            modifier = Modifier.fillMaxWidth(),
        )
        error?.let {
            Spacer(Modifier.height(8.dp))
            Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
        }
        Spacer(Modifier.height(16.dp))
        Button(
            onClick = {
                scope.launch {
                    busy = true
                    error = null
                    try {
                        val res = api.login(LoginRequest(identifier = identifier.trim(), password = password))
                        val data = res.data?.let { decodeJson<LoginData>(it) }
                        if (res.success && data != null) {
                            session.onLogin(
                                TokenPair(data.token, data.refresh, data.expiresAt),
                                data.user,
                            )
                            // Push registration: a token that arrived before
                            // login was parked in WorkManager; re-enqueue now
                            // that POST /api/devices will be accepted.
                            runCatching {
                                com.google.firebase.messaging.FirebaseMessaging
                                    .getInstance().token
                                    .addOnSuccessListener { token ->
                                        online.broxlab.aliftools.push.DeviceRegistrar
                                            .enqueueRegistration(context, token)
                                    }
                            } // no-op when google-services.json is absent
                            onLoggedIn()
                        } else {
                            error = res.message.ifEmpty { "লগইন ব্যর্থ হয়েছে।" }
                        }
                    } catch (t: Throwable) {
                        error = t.message ?: "নেটওয়ার্ক ত্রুটি"
                    } finally {
                        busy = false
                    }
                }
            },
            enabled = !busy,
        ) {
            if (busy) {
                CircularProgressIndicator(Modifier.height(18.dp))
            } else {
                Text("লগইন করুন")
            }
        }
    }
}
