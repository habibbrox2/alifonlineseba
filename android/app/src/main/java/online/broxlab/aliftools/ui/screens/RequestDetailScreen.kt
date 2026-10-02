package online.broxlab.aliftools.ui.screens

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import online.broxlab.aliftools.data.Session
import online.broxlab.aliftools.data.AlifToolsApi
import online.broxlab.aliftools.data.decodeJson

/** `data` shape of GET /api/service-requests/{id} (ServiceRequestDetailApiAction). */
@Serializable
data class ServiceRequestDetail(
    val id: Long = 0,
    val reference: String = "",
    val service: String? = null,
    val amount: Double = 0.0,
    val status: String = "",
    val status_label: String = "",
    val created_at: String = "",
)

@Composable
fun ServiceRequestDetailScreen(
    requestId: Long,
    api: AlifToolsApi,
    session: Session,
) {
    var detail by remember { mutableStateOf<ServiceRequestDetail?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(requestId) {
        try {
            val res = api.serviceRequest(requestId)
            detail = res.data?.let { decodeJson<ServiceRequestDetail>(it) }
            if (!res.success) error = res.message
        } catch (t: Throwable) {
            error = t.message ?: "নেটওয়ার্ক ত্রুটি"
        } finally {
            loading = false
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp),
    ) {
        Text("অনুরোধ বিস্তারিত", style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(12.dp))

        when {
            loading -> CircularProgressIndicator()
            error != null -> Text(error ?: "", color = MaterialTheme.colorScheme.error)
            detail == null -> Text("অনুরোধটি পাওয়া যায়নি।")
            else -> {
                val d = detail ?: return@Column
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp)) {
                        Text(d.reference, style = MaterialTheme.typography.titleMedium)
                        Text(d.service ?: "—", style = MaterialTheme.typography.bodyMedium)
                        Text("৳ ${d.amount}", style = MaterialTheme.typography.bodyLarge)
                        Text(d.status_label.ifEmpty { d.status }, style = MaterialTheme.typography.bodySmall)
                        Text(d.created_at, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}

@Composable
fun NotificationListScreen(
    api: AlifToolsApi,
    session: Session,
) {
    var rows by remember { mutableStateOf<List<NotificationRow>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        try {
            val res = api.notifications()
            rows = res.data?.let { decodeJson<NotificationsData>(it) }?.notifications ?: emptyList()
        } finally {
            loading = false
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp),
    ) {
        Row(modifier = Modifier.fillMaxWidth()) {
            Text("নোটিফিকেশন", style = MaterialTheme.typography.headlineSmall)
        }
        Spacer(Modifier.height(12.dp))

        if (loading) {
            CircularProgressIndicator()
        } else if (rows.isEmpty()) {
            Text("কোনো নোটিফিকেশন নেই।")
        } else {
            LazyColumn(verticalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(8.dp)) {
                items(rows, key = { it.id }) { row ->
                    Card(Modifier.fillMaxWidth()) {
                        Column(Modifier.padding(12.dp)) {
                            Text(row.title, style = MaterialTheme.typography.titleSmall)
                            Text(row.message, style = MaterialTheme.typography.bodySmall)
                            row.link?.let {
                                Spacer(Modifier.height(4.dp))
                                Text(it, style = MaterialTheme.typography.labelSmall)
                            }
                        }
                    }
                }
            }
        }
    }
}
