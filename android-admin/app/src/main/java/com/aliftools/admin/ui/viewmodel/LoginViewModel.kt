package com.aliftools.admin.ui.viewmodel

import android.util.Log
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.aliftools.admin.data.repository.AdminRepository
import com.google.firebase.messaging.FirebaseMessaging
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import kotlinx.coroutines.tasks.await
import javax.inject.Inject

sealed interface LoginUiState {
    data object Idle : LoginUiState
    data object Loading : LoginUiState
    data class Success(val role: String) : LoginUiState
    data class Error(val message: String) : LoginUiState
}

@HiltViewModel
class LoginViewModel @Inject constructor(
    private val repository: AdminRepository
) : ViewModel() {

    private val _state = MutableStateFlow<LoginUiState>(LoginUiState.Idle)
    val state: StateFlow<LoginUiState> = _state.asStateFlow()

    /**
     * Identifier to pre-fill on the login form, from "remember me".
     *
     * Only the username/phone is kept — never the password — so a lost or
     * shared shop phone saves typing without handing over a credential.
     */
    val rememberedIdentifier: StateFlow<String?> = repository.rememberedIdentifierFlow()
        .stateIn(viewModelScope, SharingStarted.Eagerly, null)

    /** Mirrors [rememberedIdentifier] so the checkbox ticks immediately. */
    private val _rememberMe = MutableStateFlow(false)
    val rememberMe: StateFlow<Boolean> = _rememberMe.asStateFlow()

    init {
        // Tick the box whenever something is actually stored, so the control
        // reflects persisted state rather than an optimistic default.
        viewModelScope.launch {
            repository.rememberedIdentifierFlow().collect { stored ->
                _rememberMe.value = !stored.isNullOrBlank()
            }
        }
    }

    fun login(identifier: String, password: String, rememberMe: Boolean = _rememberMe.value) {
        if (identifier.isBlank() || password.isBlank()) {
            _state.value = LoginUiState.Error("ইউজারনেম এবং পাসওয়ার্ড দিন।")
            return
        }
        viewModelScope.launch {
            _state.value = LoginUiState.Loading
            repository.login(identifier, password)
                .onSuccess { login ->
                    persistRememberMe(identifier, rememberMe)
                    // The repository only succeeds when the payload carried
                    // a user; the type is nullable because refresh omits it.
                    _state.value = LoginUiState.Success(login.user?.role ?: "")
                    registerDeviceQuietly()
                }
                .onFailure { e ->
                    _state.value = LoginUiState.Error(e.message ?: "লগইন ফেইল হয়েছে।")
                }
        }
    }

    /**
     * Signs in with a Firebase ID token (phone OTP or a federated provider)
     * instead of a password. Same success path as [login].
     */
    fun loginWithFirebase(idToken: String, provider: String = "phone", rememberMe: Boolean = _rememberMe.value) {
        if (idToken.isBlank()) {
            _state.value = LoginUiState.Error("Firebase টোকেন পাওয়া যায়নি।")
            return
        }
        viewModelScope.launch {
            _state.value = LoginUiState.Loading
            repository.loginWithFirebase(idToken, provider)
                .onSuccess { login ->
                    // A Firebase account may have no usable email/phone for
                    // the password form, so nothing is pre-filled next time.
                    repository.setRememberedIdentifier(null)
                    _state.value = LoginUiState.Success(login.user?.role ?: "")
                    registerDeviceQuietly()
                }
                .onFailure { e ->
                    _state.value = LoginUiState.Error(e.message ?: "লগইন ফেইল হয়েছে।")
                }
        }
    }

    fun setRememberMe(enabled: Boolean) {
        _rememberMe.value = enabled
        // Unchecking forgets immediately, so a shared device can be cleared
        // without waiting for the next successful login.
        if (!enabled) {
            viewModelScope.launch { repository.setRememberedIdentifier(null) }
        }
    }

    private suspend fun persistRememberMe(identifier: String, enabled: Boolean) {
        repository.setRememberedIdentifier(if (enabled) identifier else null)
    }

    /**
     * Best-effort: tells the backend which FCM token to push alerts to.
     * A build without a google-services.json has no Firebase app at all,
     * and a login must not fail over a missing push registration — the
     * device registers again on the next token refresh.
     */
    private suspend fun registerDeviceQuietly() {
        try {
            val token = FirebaseMessaging.getInstance().token.await()
            repository.registerDevice(token, "Android Admin")
        } catch (e: Exception) {
            Log.w(TAG, "Device registration skipped", e)
        }
    }

    fun reset() {
        _state.value = LoginUiState.Idle
    }

    companion object {
        private const val TAG = "AlifAdminLogin"
    }
}
