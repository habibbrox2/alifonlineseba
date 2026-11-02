package com.aliftools.admin.screenshots

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.aliftools.admin.ui.components.design.AmountBand
import com.aliftools.admin.ui.components.design.ListRow
import com.aliftools.admin.ui.components.design.RoleChip
import com.aliftools.admin.ui.components.design.SectionHeader
import com.aliftools.admin.ui.components.design.StatTile
import com.aliftools.admin.ui.components.design.StatTone
import com.aliftools.admin.ui.components.design.StatusPill
import com.aliftools.admin.ui.model.StatusTone
import com.aliftools.admin.ui.theme.AlifAdminTheme

@Composable
fun DesignSystemScreenshotContent(darkTheme: Boolean) {
    AlifAdminTheme(darkTheme = darkTheme) {
        Surface(
            modifier = Modifier.fillMaxSize(),
            color = MaterialTheme.colorScheme.background,
        ) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .verticalScroll(rememberScrollState())
                    .padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(
                    text = "ডিজাইন সিস্টেম",
                    style = MaterialTheme.typography.headlineSmall,
                    color = MaterialTheme.colorScheme.onBackground,
                )
                Text(
                    text = if (darkTheme) "ডার্ক মোড" else "লাইট মোড",
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.primary,
                )
                SectionHeader(
                    title = "স্ট্যাটাস ও ভূমিকা",
                    actionLabel = "সব দেখুন",
                    onAction = {},
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatusPill("pending")
                    StatusPill("approved")
                    StatusPill("rejected")
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    RoleChip("superadmin")
                    RoleChip("staff")
                }
                SectionHeader(title = "তালিকার সারি")
                ListRow(
                    title = "রিচার্জ অনুমোদনের অপেক্ষায়",
                    meta = "অর্ডার #1248",
                    subtitle = "মোবাইল: 01700000000",
                    amount = "1250.50",
                    status = "pending",
                    railTone = StatusTone.Warning,
                    onClick = {},
                )
                SectionHeader(title = "পরিসংখ্যান")
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    StatTile(
                        icon = Icons.Outlined.Group,
                        value = "১,২৮৪",
                        label = "মোট ব্যবহারকারী",
                        modifier = Modifier.weight(1f),
                        tone = StatTone.Brand,
                    )
                    StatTile(
                        icon = Icons.Outlined.Inbox,
                        value = "১২",
                        label = "সক্রিয় অর্ডার",
                        modifier = Modifier.weight(1f),
                        tone = StatTone.Info,
                    )
                }
                AmountBand(
                    amount = "1250.50",
                    caption = "অনুমোদনের আগে পরিমাণ যাচাই করুন",
                )
                SectionHeader(title = "টাইপোগ্রাফি")
                Text(
                    text = "শিরোনাম — বাংলা ও ইংরেজি",
                    style = MaterialTheme.typography.titleMedium,
                    color = MaterialTheme.colorScheme.onBackground,
                )
                Text(
                    text = "বডি টেক্সট, বাংলা সংখ্যা ১২৩৪৫৬ এবং ইংরেজি 123456",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    text = "লেবেল ও সহায়ক তথ্য",
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}
