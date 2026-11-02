package com.aliftools.admin.screenshots

import app.cash.paparazzi.DeviceConfig
import app.cash.paparazzi.Paparazzi
import org.junit.Rule
import org.junit.Test

class DesignSystemPaparazziTest {
    @get:Rule
    val paparazzi = Paparazzi(
        deviceConfig = DeviceConfig.PIXEL_5,
    )

    @Test
    fun lightThemeComponentsTypographyAndSpacing() {
        paparazzi.snapshot(name = "DesignSystemLight") {
            DesignSystemScreenshotContent(darkTheme = false)
        }
    }

    @Test
    fun darkThemeComponentsTypographyAndSpacing() {
        paparazzi.snapshot(name = "DesignSystemDark") {
            DesignSystemScreenshotContent(darkTheme = true)
        }
    }
}
