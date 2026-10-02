package online.broxlab.aliftools.update

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import online.broxlab.aliftools.BuildConfig
import online.broxlab.aliftools.data.AppVersion

/**
 * The update prompt.
 *
 * Two behaviours, one dialog, decided by [AppVersion.updateRequired]:
 *
 *  - **Available** — dismissible. A missing optional update is not an error and
 *    nagging about it is how people learn to dismiss update dialogs without
 *    reading them.
 *  - **Required** — not dismissible, and the copy says the app will stop
 *    working. That claim has to be earned: the server only sets it when the
 *    client is below the release's `min_version_code`, which is a decision
 *    somebody made on purpose.
 *
 * The notes are release notes in whatever language the operator typed them, and
 * a hand-entered string can be arbitrarily long — hence the scroll and the
 * height cap rather than a dialog that runs off the screen on a small phone.
 */
@Composable
fun UpdatePrompt(
    version: AppVersion,
    canInstall: Boolean,
    onDismiss: () -> Unit,
    onInstall: () -> Unit,
) {
    val required = version.updateRequired
    val size = UpdateChecker.formatSize(version.sizeBytes)

    AlertDialog(
        onDismissRequest = { if (!required) onDismiss() },
        title = {
            Text(
                text = if (required) "আপডেট আবশ্যক" else "নতুন আপডেট আছে",
                fontWeight = FontWeight.Bold,
            )
        },
        text = {
            Column(modifier = Modifier.verticalScroll(rememberScrollState())) {
                Text(
                    text = buildString {
                        append("বর্তমান ভার্সন: ")
                        append(BuildConfig.VERSION_NAME)
                        if (version.versionName.isNotBlank()) {
                            append(" → ")
                            append(version.versionName)
                        }
                        if (size.isNotBlank()) {
                            append("  (")
                            append(size)
                            append(")")
                        }
                    },
                    style = MaterialTheme.typography.bodyMedium,
                )

                if (required) {
                    Spacer(Modifier.height(12.dp))
                    Text(
                        text = "এই ভার্সনটি আর কাজ করবে না। আপডেট ইনস্টল না করা পর্যন্ত সার্ভিস ব্যবহার করা যাবে না।",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.error,
                    )
                }

                if (!version.releaseNotes.isNullOrBlank()) {
                    Spacer(Modifier.height(12.dp))
                    Text(
                        text = "এই আপডেটে যা আছে",
                        style = MaterialTheme.typography.labelLarge,
                        fontWeight = FontWeight.Bold,
                    )
                    Spacer(Modifier.height(4.dp))
                    Text(
                        text = version.releaseNotes,
                        style = MaterialTheme.typography.bodySmall,
                        modifier = Modifier.heightIn(max = 220.dp),
                    )
                }

                if (!canInstall) {
                    Spacer(Modifier.height(12.dp))
                    Text(
                        text = "আপনাকে প্রথমে সেটিংস থেকে এই অ্যাপের জন্য \"অজানা উৎস থেকে ইনস্টল\" অনুমতি দিতে হবে।",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onInstall) {
                Text(if (canInstall) "ডাউনলোড ও ইনস্টল" else "সেটিংসে যান")
            }
        },
        dismissButton = {
            if (!required) {
                TextButton(onClick = onDismiss) { Text("পরে") }
            }
        },
    )
}

/**
 * The transient states the flow passes through: downloading, and the failure to
 * hand the file over. Rendered inline under the dialog rather than as a second
 * dialog, because a second modal on top of a modal is a trap on a phone.
 */
@Composable
fun UpdateProgress(
    working: Boolean,
    message: String?,
    onDismiss: () -> Unit,
) {
    if (!working && message == null) {
        return
    }
    AlertDialog(
        onDismissRequest = { if (!working) onDismiss() },
        text = {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (working) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                    Spacer(Modifier.width(12.dp))
                }
                Text(text = message ?: "ডাউনলোড হচ্ছে…")
            }
        },
        confirmButton = {
            if (!working) {
                TextButton(onClick = onDismiss) { Text("ঠিক আছে") }
            }
        },
    )
}
