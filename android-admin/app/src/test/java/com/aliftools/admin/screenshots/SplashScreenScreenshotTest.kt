package com.aliftools.admin.screenshots

import app.cash.paparazzi.DeviceConfig
import app.cash.paparazzi.Paparazzi
import com.aliftools.admin.ui.screens.splash.SplashScreen
import com.aliftools.admin.ui.theme.AlifAdminTheme
import org.junit.Rule
import org.junit.Test

class SplashScreenScreenshotTest {
    @get:Rule
    val paparazzi = Paparazzi(
        deviceConfig = DeviceConfig.PIXEL_5,
    )

    @Test
    fun splashScreen() {
        paparazzi.snapshot {
            AlifAdminTheme {
                SplashScreen()
            }
        }
    }
}
