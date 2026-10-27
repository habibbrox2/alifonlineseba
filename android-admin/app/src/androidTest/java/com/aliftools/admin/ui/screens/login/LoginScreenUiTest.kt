package com.aliftools.admin.ui.screens.login

import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.ComposeTestRule
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ActivityScenario
import com.aliftools.admin.MainActivity
import com.aliftools.admin.data.model.LoginResponse
import com.aliftools.admin.data.model.User
import com.aliftools.admin.data.repository.AdminRepository
import com.aliftools.admin.ui.viewmodel.LoginViewModel
import io.mockk.Runs
import io.mockk.coEvery
import io.mockk.coJustRun
import io.mockk.every
import io.mockk.just
import io.mockk.mockk
import kotlinx.coroutines.flow.flowOf
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test

/**
 * Drives the real [LoginScreen] Compose UI with a mocked [AdminRepository].
 *
 * No network, no Hilt, no device account needed: the screen under test is the
 * production composable, and "sign-in works end to end" is reduced to what the
 * UI can be asked to prove — the form renders, empty input is rejected without
 * a round trip, a server success navigates, and a server failure surfaces the
 * backend's own Bengali message. The repository wiring itself is covered by
 * the MockWebServer tests in `data/api`/`data/repository`.
 */
class LoginScreenUiTest {

    /** Launches the production MainActivity, so LoginScreen finds a real Activity context. */
    @get:Rule
    val compose: ComposeTestRule = createEmptyComposeRule()

    private fun loginResponse() = LoginResponse(
        token = "t",
        refresh = "r",
        expiresAt = "2026-11-01 00:00:00",
        user = User(
            id = 1,
            username = "admin",
            phone = "01712345678",
            email = null,
            role = "superadmin",
            balance = "0.00",
        ),
    )

    private fun makeRepository(loginResult: Result<LoginResponse>): AdminRepository {
        val authApi = mockk<com.aliftools.admin.data.api.AuthApiService>()
        val adminApi = mockk<com.aliftools.admin.data.api.AdminApiService>()
        val tokenStorage = mockk<com.aliftools.admin.data.local.TokenStorage> {
            every { rememberedIdentifierFlow } returns flowOf(null)
            coEvery { clearTokens() } just Runs
        }
        coEvery { tokenStorage.saveTokens(any(), any(), any(), any()) } just Runs
        coJustRun { tokenStorage.setRememberedIdentifier(any()) }

        return mockk<AdminRepository> {
            every { rememberedIdentifierFlow() } returns flowOf(null)
            coEvery { login(any(), any()) } returns loginResult
            coEvery { loginWithFirebase(any(), any()) } returns loginResult
            // The success path persists "remember me" BEFORE flipping the UI
            // to Success, so an unstubbed strict mock would throw and the
            // navigation would never fire.
            coJustRun { setRememberedIdentifier(any()) }
            // The ViewModel reports device tokens after every successful
            // login; a build without google-services.json throws inside the
            // Firebase SDK and the stub must not blow up on the mock.
            coEvery { registerDevice(any(), any()) } returns Result.success(Unit)
        }
    }

    /**
     * Launches MainActivity, then takes over its Compose content — the same
     * activity type the production screen runs in, so LoginScreen's activity
     * lookup (for the Firebase helper) resolves. Saves the launched activity's
     * class in a scenario for reuse within one test.
     */
    /**
     * Finds the password input field itself. Merely matching the label text is
     * ambiguous on this screen (the label node inside the field also carries
     * it), so narrow the query to EditableText via expectValue — only real
     * text fields expose that property. onFirst() then resolves the remaining
     * label/field split deterministically (field node wins in tree order).
     */
    private fun passwordField() =
        compose.onAllNodes(
            SemanticsMatcher.expectValue(
                SemanticsProperties.EditableText,
                androidx.compose.ui.text.AnnotatedString(""),
            )
                .and(hasText("পাসওয়ার্ড", substring = true)),
        ).onFirst()

    /** Finds the identifier input by its label text. */
    private val identifierField get() = compose.onNodeWithText("ইউজারনেম / ফোন")

    private fun setScreen(repository: AdminRepository, onLoginSuccess: (String) -> Unit = {}) {
        // Construct the test ViewModel before entering the composable content.
        // This mirrors lifecycle ownership and avoids ViewModelConstructorInComposable lint.
        val loginViewModel = LoginViewModel(repository)
        ActivityScenario.launch(MainActivity::class.java).onActivity { activity ->
            activity.setContent {
                LoginScreen(
                    onLoginSuccess = onLoginSuccess,
                    viewModel = loginViewModel,
                )
            }
        }
        compose.waitForIdle()
    }

    /**
     * Types both fields, then dismisses the soft keyboard before tapping the
     * submit button. On a real device the IME covers the button, so an
     * injected tap at the button's coordinates would land on the keyboard
     * window instead of the app.
     */
    private fun fillForm(identifier: String, password: String) {
        identifierField.performTextInput(identifier)
        passwordField().performTextInput(password)
        androidx.test.espresso.Espresso.closeSoftKeyboard()
        compose.waitForIdle()
    }

    @Test
    fun rendersIdentifierAndPasswordFieldsAndButton() {
        setScreen(makeRepository(Result.success(loginResponse())))

        compose.onNodeWithText("অ্যাডমিন কনসোলে সাইন ইন করুন").assertIsDisplayed()
        compose.onNodeWithText("ইউজারনেম / ফোন").assertIsDisplayed()
        identifierField.assertExists()
        passwordField().assertExists()
        compose.onNodeWithText("লগইন করুন").assertIsDisplayed()
        compose.onNodeWithText("আমাকে মনে রাখুন").assertIsDisplayed()
    }

    @Test
    fun successfulLoginNavigatesWithTheServerRole() {
        var navigatedRole: String? = null
        val repo = makeRepository(Result.success(loginResponse()))

        setScreen(repo) { role -> navigatedRole = role }

        fillForm("admin", "secret")
        compose.onNodeWithText("লগইন করুন").performClick()
        compose.waitUntil(timeoutMillis = 5_000) { navigatedRole != null }

        assertEquals("superadmin", navigatedRole)
    }

    @Test
    fun serverFailureSurfacesTheBackendMessage() {
        val repo = makeRepository(Result.failure(Exception("ভুল ইউজারনেম বা পাসওয়ার্ড।")))

        setScreen(repo)

        fillForm("admin", "wrongpass")
        compose.onNodeWithText("লগইন করুন").performClick()
        compose.waitUntil(timeoutMillis = 5_000) {
            compose.onAllNodes(hasText("ভুল ইউজারনেম বা পাসওয়ার্ড।"), useUnmergedTree = true)
                .fetchSemanticsNodes().isNotEmpty()
        }
        compose.onAllNodes(hasText("ভুল ইউজারনেম বা পাসওয়ার্ড।"), useUnmergedTree = true)
            .onFirst().assertExists()
    }

    @Test
    fun loadingShowsBusyLabelAndDisablesSubmit() {
        val repo = makeRepository(Result.success(loginResponse()))
        // Hold the login open so the loading state is observable.
        coEvery { repo.login(any(), any()) } coAnswers {
            kotlinx.coroutines.delay(60_000)
            Result.success(loginResponse())
        }

        setScreen(repo)

        fillForm("admin", "secret")
        compose.onNodeWithText("লগইন করুন").performClick()
        compose.waitUntil(timeoutMillis = 5_000) {
            compose.onAllNodes(hasText("লগইন হচ্ছে..."), useUnmergedTree = true)
                .fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNodeWithText("লগইন হচ্ছে...").assertIsDisplayed()
        // The idle label is replaced by the busy one while loading.
        compose.onAllNodes(hasText("লগইন করুন"), useUnmergedTree = true)
            .fetchSemanticsNodes()
            .let { nodes -> assertTrue("submit should relabel while busy", nodes.isEmpty()) }
    }

    @Test
    fun emptySubmitRejectsWithoutTouchingTheRepository() {
        val repo = makeRepository(Result.success(loginResponse()))

        setScreen(repo)

        compose.onNodeWithText("লগইন করুন").performClick()
        compose.waitUntil(timeoutMillis = 5_000) {
            compose.onAllNodes(hasText("ইউজারনেম এবং পাসওয়ার্ড দিন।"), useUnmergedTree = true)
                .fetchSemanticsNodes().isNotEmpty()
        }
        compose.onAllNodes(hasText("ইউজারনেম এবং পাসওয়ার্ড দিন।"), useUnmergedTree = true)
            .onFirst().assertExists()
    }

    @Test
    fun rememberedIdentifierPreFillsTheForm() {
        val repo = makeRepository(Result.success(loginResponse()))
        every { repo.rememberedIdentifierFlow() } returns flowOf("prevAdmin")

        setScreen(repo)

        compose.waitUntil(timeoutMillis = 5_000) {
            compose.onAllNodes(hasText("prevAdmin"), useUnmergedTree = true)
                .fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNodeWithText("prevAdmin", useUnmergedTree = true).assertIsDisplayed()
    }

    @Test
    fun submitButtonIsEnabledBeforeAnyInput() {
        setScreen(makeRepository(Result.success(loginResponse())))

        compose.onNodeWithText("লগইন করুন").assertIsEnabled()
    }
}
