package com.aliftools.admin.ui.format

import java.math.BigDecimal
import java.text.NumberFormat
import java.util.Locale

/**
 * One money formatter for the whole app.
 *
 * Mirrors TwigExtension::money ("৳ " + number_format($amount, 2)): Latin digits,
 * grouped thousands, always two decimals. Bengali digits here would give the
 * same balance two spellings between web and app.
 */
object Money {

    private val numberFormat: NumberFormat
        get() = NumberFormat.getNumberInstance(Locale.US).apply {
            minimumFractionDigits = 2
            maximumFractionDigits = 2
            isGroupingUsed = true
        }

    fun format(amount: String?): String {
        val raw = amount?.trim().orEmpty()
        if (raw.isEmpty()) return "৳ 0.00"
        val value = raw.replace(",", "").toBigDecimalOrNull()
            // Never invent a zero for an unparsable server value: show what
            // arrived so the operator sees a data problem, not a false balance.
            ?: return "৳ $raw"
        return "৳ " + numberFormat.format(value)
    }

    private fun String.toBigDecimalOrNull(): BigDecimal? =
        try {
            BigDecimal(this)
        } catch (_: NumberFormatException) {
            null
        }
}
