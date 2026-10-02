package online.broxlab.aliftools.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import online.broxlab.aliftools.data.Session
import online.broxlab.aliftools.data.AlifToolsApi
import online.broxlab.aliftools.data.decodeJson

/** Shape of each row in `data.notifications` from GET /api/notifications. */
@Serializable
data class NotificationRow(
    val id: Long = 0,
    val title: String = "",
    val message: String = "",
    val type: String = "info",
    val link: String? = null,
    val read_at: String? = null,
)

@Composable
fun DashboardScreen(
    session: Session,
    api: AlifToolsApi,
    onOpenRequest: (Long) -> Unit,
    onOpenNotifications: () -> Unit,
    onLogout: () -> Unit,
) {
    var rows by remember { mutableStateOf<List<NotificationRow>>(emptyList()) }
    var unread by remember { mutableStateOf(0) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val user = session.user.value

    suspend fun load() {
        try {
            val res = api.notifications()
            val data = res.data?.let { decodeJson<NotificationsData>(it) }
            rows = data?.notifications ?: emptyList()
            unread = data?.unread ?: 0
            error = null
        } catch (t: Throwable) {
            error = t.message
        } finally {
            loading = false
        }
    }

    LaunchedEffect(Unit) { load() }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column {
                Text("হ্যালো, ${user?.username ?: ""}", style = MaterialTheme.typography.headlineSmall)
                Text("ব্যালেন্স: ৳ ${user?.balance ?: 0.0}", style = MaterialTheme.typography.bodyLarge)
            }
            OutlinedButton(onClick = onLogout) { Text("লগআউট") }
        }

        Spacer(Modifier.height(16.dp))
        AssistChip(
            onClick = onOpenNotifications,
            label = { Text("নোটিফিকেশন${if (unread > 0) " ($unread)" else ""}") },
        )
        Spacer(Modifier.height(16.dp))

        when {
            loading -> CircularProgressIndicator()
            error != null -> Text(
                error ?: "",
                color = MaterialTheme.colorScheme.error,
                style = MaterialTheme.typography.bodySmall,
            )
            rows.isEmpty() -> Text("কোনো নোটিফিকেশন নেই।")
            else -> LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                items(rows, key = { it.id }) { row ->
                    Card(modifier = Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp)) {
                            Text(row.title, style = MaterialTheme.typography.titleSmall)
                            Text(row.message, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
        }
    }
}

@Serializable
data class NotificationsData(
    val notifications: List<NotificationRow> = emptyList(),
    val total: Int = 0,
    val unread: Int = 0,
)
