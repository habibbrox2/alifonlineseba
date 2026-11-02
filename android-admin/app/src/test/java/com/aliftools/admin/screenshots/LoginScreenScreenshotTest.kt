package com.aliftools.admin.screenshots

import app.cash.paparazzi.DeviceConfig
import app.cash.paparazzi.Paparazzi
import com.aliftools.admin.data.repository.AdminRepository
import com.aliftools.admin.ui.screens.login.LoginScreen
import com.aliftools.admin.ui.theme.AlifAdminTheme
import com.aliftools.admin.ui.viewmodel.LoginViewModel
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.flow.MutableStateFlow
import org.junit.Rule
import org.junit.Test

class LoginScreenScreenshotTest {
    @get:Rule
    val paparazzi = Paparazzi(
        deviceConfig = DeviceConfig.PIXEL_5,
    )

    @Test
    fun loginScreen() {
        val repository = mockk<AdminRepository>(relaxed = true)
        every { repository.rememberedIdentifierFlow() } returns MutableStateFlow(null)
        val viewModel = LoginViewModel(repository)

        paparazzi.snapshot {
            AlifAdminTheme {
                LoginScreen(onLoginSuccess = {}, viewModel = viewModel)
            }
        }
    }
}
