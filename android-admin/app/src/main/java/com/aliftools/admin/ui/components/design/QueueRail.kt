package com.aliftools.admin.ui.components.design

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.aliftools.admin.ui.theme.AlifTheme

data class QueueItem(
    val key: String,
    val label: String,
    val count: Int,
)

/**
 * The queue rail: three live counts overlapping the emerald hero. Gold appears
 * only on a non-zero count; zero is a result and gets a check.
 */
@Composable
fun QueueRail(
    items: List<QueueItem>,
    onSelect: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val colors = AlifTheme.colors
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.extraLarge,
        color = MaterialTheme.colorScheme.surface,
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        shadowElevation = 2.dp,
    ) {
        Row(modifier = Modifier.fillMaxWidth()) {
            items.forEachIndexed { index, item ->
                Column(
                    modifier = Modifier
                        .weight(1f)
                        .clickable(role = Role.Button) { onSelect(item.key) }
                        .semantics(mergeDescendants = true) {
                            contentDescription = "${item.count}টি ${item.label}"
                        }
                        .padding(horizontal = 12.dp, vertical = 11.dp),
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                ) {
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        Text(
                            text = item.count.toString(),
                            style = MaterialTheme.typography.headlineSmall.copy(
                                fontSize = 26.sp,
                                lineHeight = 30.sp,
                            ),
                            color = if (item.count > 0) {
                                MaterialTheme.colorScheme.onSurface
                            } else {
                                MaterialTheme.colorScheme.outline
                            },
                        )
                        if (item.count > 0) {
                            Box(
                                modifier = Modifier
                                    .size(6.dp)
                                    .background(colors.gold, CircleShape),
                            )
                        } else {
                            Icon(
                                imageVector = Icons.Filled.Check,
                                contentDescription = null,
                                modifier = Modifier.size(15.dp),
                                tint = colors.success,
                            )
                        }
                    }
                    Text(
                        text = item.label,
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                    )
                }
                if (index < items.lastIndex) {
                    Box(
                        modifier = Modifier
                            .width(1.dp)
                            .height(52.dp)
                            .align(Alignment.CenterVertically)
                            .background(MaterialTheme.colorScheme.outlineVariant),
                    )
                }
            }
        }
    }
}
