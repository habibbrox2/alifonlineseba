package com.aliftools.admin.screenshots

import com.github.takahirom.roborazzi.captureRoboImage
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.RobolectricTestRunner

@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [36], qualifiers = "w393dp-h851dp-mdpi")
class DesignSystemRoborazziTest {
    @Test
    fun lightThemeComponentsTypographyAndSpacing() {
        captureRoboImage {
            DesignSystemScreenshotContent(darkTheme = false)
        }
    }

    @Test
    fun darkThemeComponentsTypographyAndSpacing() {
        captureRoboImage {
            DesignSystemScreenshotContent(darkTheme = true)
        }
    }
}
