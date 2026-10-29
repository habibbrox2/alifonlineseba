package com.aliftools.admin.ui.format

import org.junit.Assert.assertEquals
import org.junit.Test

class MoneyTest {

    @Test
    fun `formats with grouping and two decimals`() {
        assertEquals("৳ 1,250.00", Money.format("1250"))
        assertEquals("৳ 12,400.50", Money.format("12400.5"))
    }

    @Test
    fun `accepts server strings that already carry separators`() {
        assertEquals("৳ 1,200.00", Money.format("1,200"))
    }

    @Test
    fun `missing amount is zero`() {
        assertEquals("৳ 0.00", Money.format(null))
        assertEquals("৳ 0.00", Money.format(" "))
    }

    @Test
    fun `unparsable value is shown rather than faked`() {
        assertEquals("৳ n/a", Money.format("n/a"))
    }
}
