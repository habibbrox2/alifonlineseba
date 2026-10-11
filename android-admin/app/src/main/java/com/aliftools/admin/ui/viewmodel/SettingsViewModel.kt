package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import javax.inject.Inject

/**
 * Settings is not a list: GET /api/admin/settings hands back the whole
 * key→value map SettingsRepository::all() builds, and POST takes the same
 * map back. The form is therefore "render every key we were given" — new
 * backend keys appear without an app release.
 */
@HiltViewModel
class SettingsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ActionViewModel() {

    sealed interface UiState {
        data object Loading : UiState
        data class Loaded(val values: Map<String, String>) : UiState
        data class Error(val message: String) : UiState
    }

    private val _uiState = MutableStateFlow<UiState>(UiState.Loading)
    val uiState: StateFlow<UiState> = _uiState.asStateFlow()

    init {
        load()
    }

    fun load() {
        _uiState.value = UiState.Loading
        // A reload after save re-reads the canonical map (server normalises
        // checkboxes and numbers), so a saved form agrees with the database.
        runAction(
            refreshList = false,
            messageOf = { "" },
            block = { repository.settings() },
            onSuccess = { values -> _uiState.value = UiState.Loaded(values) },
            onFailure = { error ->
                _uiState.value = UiState.Error(error.message ?: "সেটিংস লোড ব্যর্থ হয়েছে")
            },
        )
    }

    fun onValueChange(key: String, value: String) {
        val current = _uiState.value
        if (current is UiState.Loaded) {
            _uiState.value = UiState.Loaded(current.values + (key to value))
        }
    }

    fun save() {
        val current = _uiState.value
        if (current !is UiState.Loaded) return
        val snapshot = current.values
        runAction(
            messageOf = { "সাইট সেটিংস সংরক্ষণ হয়েছে" },
            block = { repository.saveSettings(snapshot) },
            onSuccess = { _uiState.value = UiState.Loaded(snapshot) },
        )
    }
}
