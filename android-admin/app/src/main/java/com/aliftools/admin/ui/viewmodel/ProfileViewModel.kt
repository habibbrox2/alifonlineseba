package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.AdminProfile
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject

@HiltViewModel
class ProfileViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ActionViewModel() {

    sealed interface UiState {
        data object Loading : UiState
        data class Loaded(val profile: AdminProfile) : UiState
        data class Error(val message: String) : UiState
    }

    private val _uiState = MutableStateFlow<UiState>(UiState.Loading)
    val uiState: StateFlow<UiState> = _uiState.asStateFlow()

    init {
        load()
    }

    fun load() {
        _uiState.value = UiState.Loading
        runAction(
            refreshList = false,
            messageOf = { "" },
            block = { repository.profile() },
            onSuccess = { profile -> _uiState.value = UiState.Loaded(profile) },
            onFailure = { error ->
                _uiState.value = UiState.Error(error.message ?: "প্রোফাইল লোড করা যায়নি")
            },
        )
    }

    fun updateProfile(fullName: String, phone: String, email: String) {
        runAction(
            refreshList = false,
            messageOf = { "প্রোফাইল সফলভাবে আপডেট করা হয়েছে" },
            block = {
                repository.updateProfile(
                    fullName = fullName.trim(),
                    phone = phone.trim(),
                    email = email.trim(),
                )
            },
            onSuccess = { updated -> _uiState.value = UiState.Loaded(updated) },
        )
    }

    fun changePassword(
        currentPass: String,
        newPass: String,
        confirmPass: String,
        onSuccess: () -> Unit = {},
    ) {
        runAction(
            refreshList = false,
            messageOf = { "পাসওয়ার্ড সফলভাবে পরিবর্তন করা হয়েছে" },
            block = { repository.changePassword(currentPass, newPass, confirmPass) },
            onSuccess = { updated ->
                _uiState.value = UiState.Loaded(updated)
                onSuccess()
            },
        )
    }
}

