package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.BuildConfig
import com.aliftools.admin.data.api.AppVersion
import com.aliftools.admin.data.model.AdminDashboard
import com.aliftools.admin.data.repository.AdminRepository
import io.mockk.coEvery
import io.mockk.every
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@OptIn(kotlinx.coroutines.ExperimentalCoroutinesApi::class)
class DashboardViewModelTest {

    private val repository = mockk<AdminRepository>()
    private val dispatcher = StandardTestDispatcher()

    @Before
    fun setUp() = Dispatchers.setMain(dispatcher)

    @After
    fun tearDown() = Dispatchers.resetMain()

    private fun appVersion(versionCode: Int, minVersionCode: Int) =
        AppVersion(
            versionCode = versionCode,
            versionName = "1.0.$versionCode",
            minVersionCode = minVersionCode,
            releaseNotes = "notes",
            downloadUrl = "/app/apk?channel=admin",
        )

    private fun dashboard(): AdminDashboard = AdminDashboard()

    @Test
    fun `update available surfaces when server offers newer`() = runTest {
        coEvery { repository.appVersion() } returns Result.success(appVersion(BuildConfig.VERSION_CODE + 1, 1))
        every { repository.userRoleFlow() } returns flowOf<String?>(null)
        coEvery { repository.dashboard() } returns Result.failure(Exception("boom"))

        val vm = DashboardViewModel(repository)
        dispatcher.scheduler.advanceUntilIdle()

        assertEquals(BuildConfig.VERSION_CODE + 1, vm.update.value?.versionCode)
    }

    @Test
    fun `no update when server matches or older`() = runTest {
        coEvery { repository.appVersion() } returns Result.success(appVersion(BuildConfig.VERSION_CODE, 1))
        every { repository.userRoleFlow() } returns flowOf<String?>(null)
        coEvery { repository.dashboard() } returns Result.failure(Exception("boom"))

        val vm = DashboardViewModel(repository)
        dispatcher.scheduler.advanceUntilIdle()

        assertNull(vm.update.value)
    }

    @Test
    fun `update check failure leaves dashboard intact`() = runTest {
        coEvery { repository.appVersion() } returns Result.failure(Exception("server down"))
        every { repository.userRoleFlow() } returns flowOf<String?>(null)
        coEvery { repository.dashboard() } returns Result.success(dashboard())

        val vm = DashboardViewModel(repository)
        dispatcher.scheduler.advanceUntilIdle()

        assertNull(vm.update.value)
        val state = vm.state.value
        assertTrue(state is DashboardUiState.Success)
    }

    @Test
    fun `required flag from minVersionCode`() = runTest {
        coEvery { repository.appVersion() } returns Result.success(appVersion(BuildConfig.VERSION_CODE, 999))
        every { repository.userRoleFlow() } returns flowOf<String?>(null)
        coEvery { repository.dashboard() } returns Result.failure(Exception("boom"))

        val vm = DashboardViewModel(repository)
        dispatcher.scheduler.advanceUntilIdle()

        assertTrue(vm.updateRequired.value)
    }
}
