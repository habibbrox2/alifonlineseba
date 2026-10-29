package com.aliftools.admin.ui.components.design

import android.content.res.Configuration
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountBalanceWallet
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Group
import androidx.compose.material.icons.outlined.Home
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import com.aliftools.admin.ui.theme.AlifAdminTheme
import com.aliftools.admin.ui.theme.PageSurface

/**
 * One gallery instead of a preview per file: the visual contract for the kit
 * lives in one place, so a token change is reviewed against every component it
 * affects at once.
 */
// Dark and 200% font-scale variants of the same gallery keep the contract
// reviewable without a preview function per component per mode.
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, heightDp = 420)
@Preview(
    showBackground = true,
    backgroundColor = 0xFF08120F,
    uiMode = Configuration.UI_MODE_NIGHT_YES,
    heightDp = 420,
)
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, widthDp = 800, heightDp = 300)
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, heightDp = 620, fontScale = 2f)
@Composable
private fun StatusPillsPreview() {
    AlifAdminTheme {
        Column(
            modifier = Modifier
                .background(PageSurface)
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatusPill("pending")
                StatusPill("review")
                StatusPill("approved")
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatusPill("processing")
                StatusPill("completed")
                StatusPill("rejected")
            }
            MoneyText(amount = "12400.5")
            MoneyText(amount = "1250")
            RoleChip("superadmin")
        }
    }
}

@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6)
@Preview(
    showBackground = true,
    backgroundColor = 0xFF08120F,
    uiMode = Configuration.UI_MODE_NIGHT_YES,
)
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, widthDp = 800)
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, fontScale = 2f, heightDp = 480)
@Composable
private fun QueueRailPreview() {
    AlifAdminTheme {
        QueueRail(
            items = listOf(
                QueueItem("orders", "অর্ডার", 4),
                QueueItem("recharges", "রিচার্জ", 2),
                QueueItem("withdraws", "উত্তোলন", 0),
            ),
            onSelect = {},
            modifier = Modifier
                .background(PageSurface)
                .padding(16.dp),
        )
    }
}

@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6)
@Preview(
    showBackground = true,
    backgroundColor = 0xFF08120F,
    uiMode = Configuration.UI_MODE_NIGHT_YES,
)
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, widthDp = 800)
@Composable
private fun StatTilesPreview() {
    AlifAdminTheme {
        Column(
            modifier = Modifier
                .background(PageSurface)
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                StatTile(
                    icon = Icons.Outlined.Group,
                    value = "1,284",
                    label = "মোট ইউজার",
                    modifier = Modifier.weight(1f),
                )
                StatTile(
                    icon = Icons.Outlined.Inbox,
                    value = "12",
                    label = "সক্রিয় অর্ডার",
                    modifier = Modifier.weight(1f),
                    tone = StatTone.Info,
                )
            }
            MoneyText(amount = "8900")
        }
    }
}

@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6)
@Preview(
    showBackground = true,
    backgroundColor = 0xFF08120F,
    uiMode = Configuration.UI_MODE_NIGHT_YES,
)
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, widthDp = 800)
@Preview(showBackground = true, backgroundColor = 0xFFF4F7F6, fontScale = 2f, heightDp = 220)
@Composable
private fun BottomBarPreview() {
    AlifAdminTheme {
        AdminBottomBar(
            tabs = listOf(
                AdminTab("dashboard", "হোম", Icons.Outlined.Home),
                AdminTab("orders", "অর্ডার", Icons.Outlined.Inbox),
                AdminTab("recharges", "রিচার্জ", Icons.Outlined.AccountBalanceWallet),
                moreTab(),
            ),
            selectedRoute = "orders",
            moreSelected = false,
            onSelect = {},
            modifier = Modifier.fillMaxWidth(),
        )
    }
}
