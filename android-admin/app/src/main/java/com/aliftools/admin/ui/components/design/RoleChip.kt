package com.aliftools.admin.ui.components.design

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.aliftools.admin.ui.theme.AlifTheme

/** Role context for the greeting row; gold marks superadmin, not decoration. */
@Composable
fun RoleChip(role: String?, modifier: Modifier = Modifier) {
    val colors = AlifTheme.colors
    val normalized = role?.lowercase().orEmpty()
    val isSuper = normalized.contains("super")
    val isAdmin = isSuper || normalized.contains("admin")
    val label = when {
        isSuper -> "সুপার অ্যাডমিন"
        isAdmin -> "অ্যাডমিন"
        normalized.isBlank() -> "—"
        else -> "স্টাফ"
    }
    val container = when {
        isSuper -> colors.goldContainer
        isAdmin -> MaterialTheme.colorScheme.primaryContainer
        else -> colors.neutralContainer
    }
    val ink = when {
        isSuper -> colors.goldInk
        isAdmin -> MaterialTheme.colorScheme.onPrimaryContainer
        else -> colors.neutralInk
    }

    Surface(
        modifier = modifier.height(24.dp),
        shape = CircleShape,
        color = container,
        contentColor = ink,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                imageVector = Icons.Outlined.Shield,
                contentDescription = null,
                modifier = Modifier.size(12.dp),
            )
            Text(
                text = label,
                modifier = Modifier.padding(start = 4.dp),
                style = MaterialTheme.typography.labelSmall,
                maxLines = 1,
            )
        }
    }
}
