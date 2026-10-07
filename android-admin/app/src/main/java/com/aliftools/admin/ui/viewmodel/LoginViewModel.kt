package com.aliftools.admin.ui.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
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

    fun login(identifier: String, password: String) {
        if (identifier.isBlank() || password.isBlank()) {
            _state.value = LoginUiState.Error("ইউজারনেম এবং পাসওয়ার্ড দিন।")
            return
        }
        viewModelScope.launch {
            _state.value = LoginUiState.Loading
            repository.login(identifier, password)
                .onSuccess { login ->
                    _state.value = LoginUiState.Success(login.user.role)
                }
                .onFailure { e ->
                    _state.value = LoginUiState.Error(e.message ?: "লগইন ফেইল হয়েছে।")
                }
        }
    }

    fun reset() {
        _state.value = LoginUiState.Idle
    }
}
