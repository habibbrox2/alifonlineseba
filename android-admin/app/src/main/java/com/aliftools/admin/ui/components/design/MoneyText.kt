package com.aliftools.admin.ui.components.design

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.style.TextOverflow
import com.aliftools.admin.ui.format.Money
import com.aliftools.admin.ui.theme.MoneyStyle

/**
 * Money in one style everywhere: ৳, grouped thousands, two decimals, tabular
 * figures. TalkBack hears the figure plus "টাকা" rather than the currency glyph.
 */
@Composable
fun MoneyText(
    amount: String?,
    modifier: Modifier = Modifier,
    style: TextStyle = MoneyStyle,
    color: Color = Color.Unspecified,
    maxLines: Int = 1,
) {
    val formatted = Money.format(amount)
    Text(
        text = formatted,
        modifier = modifier.clearAndSetSemantics {
            contentDescription = formatted.removePrefix("৳ ") + " টাকা"
        },
        style = style,
        color = color,
        maxLines = maxLines,
        overflow = TextOverflow.Ellipsis,
    )
}
