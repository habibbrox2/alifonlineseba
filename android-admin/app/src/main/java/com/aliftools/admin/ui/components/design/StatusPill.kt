package com.aliftools.admin.ui.components.design

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.aliftools.admin.ui.model.StatusTone
import com.aliftools.admin.ui.model.Statuses
import com.aliftools.admin.ui.theme.AlifTheme

/**
 * Status pill: the only place a status colour is allowed to appear.
 *
 * In-flight states (pending / processing / review) carry a small ring, mirroring
 * the web's `.badge-loading`; a settled row must never keep spinning.
 */
@Composable
fun StatusPill(
    status: String?,
    modifier: Modifier = Modifier,
) {
    val style = Statuses.of(status)
    val colors = AlifTheme.colors
    val (container, ink) = when (style.tone) {
        StatusTone.Warning -> colors.warningContainer to colors.warningInk
        StatusTone.Info -> colors.infoContainer to colors.infoInk
        StatusTone.Success -> colors.successContainer to colors.successInk
        StatusTone.Danger -> colors.dangerContainer to colors.dangerInk
        StatusTone.Neutral -> colors.neutralContainer to colors.neutralInk
    }

    Surface(
        modifier = modifier.height(26.dp),
        shape = CircleShape,
        color = container,
        contentColor = ink,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 9.dp),
            horizontalArrangement = Arrangement.spacedBy(5.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            if (style.inFlight) {
                CircularProgressIndicator(
                    modifier = Modifier.size(9.dp),
                    strokeWidth = 1.6.dp,
                    color = ink,
                )
            }
            Text(
                text = style.label,
                style = MaterialTheme.typography.labelMedium,
                maxLines = 1,
            )
        }
    }
}
