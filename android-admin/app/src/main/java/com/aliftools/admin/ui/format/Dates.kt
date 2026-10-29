package com.aliftools.admin.ui.format

import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.time.format.DateTimeParseException
import java.time.temporal.ChronoUnit

/**
 * Relative time for home/activity glance copy ("2 মিনিট আগে").
 *
 * Latin digits, like every other number in the app. Anything unparseable
 * returns null so the caller can hide the line instead of showing a wrong
 * or empty timestamp.
 */
object Dates {

    private val server = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")
    private val display = DateTimeFormatter.ofPattern("dd-MM-yyyy")

    fun relative(raw: String?, now: LocalDateTime = LocalDateTime.now()): String? {
        val text = raw?.trim().orEmpty()
        if (text.isEmpty()) return null
        val parsed = parse(text) ?: return null
        val minutes = ChronoUnit.MINUTES.between(parsed, now)
        return when {
            minutes < 1 -> "এখন"
            minutes < 60 -> "$minutes মিনিট আগে"
            minutes < 60 * 24 -> "${minutes / 60} ঘণ্টা আগে"
            minutes < 60 * 24 * 7 -> "${minutes / (60 * 24)} দিন আগে"
            else -> parsed.format(display)
        }
    }

    private fun parse(text: String): LocalDateTime? = runCatching {
        LocalDateTime.parse(text, server)
    }.getOrNull() ?: runCatching {
        // Some payloads carry ISO-8601 with 'T'.
        LocalDateTime.parse(text.replace(' ', 'T'))
    }.getOrNull()
}
