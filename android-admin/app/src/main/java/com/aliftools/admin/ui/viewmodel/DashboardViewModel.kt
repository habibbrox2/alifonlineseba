package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.BuildConfig
import androidx.lifecycle.viewModelScope
import com.aliftools.admin.data.model.AdminDashboard
import com.aliftools.admin.data.api.AppVersion
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

sealed interface DashboardUiState {
    data object Loading : DashboardUiState
    data class Success(val data: AdminDashboard) : DashboardUiState
    data class Error(val message: String) : DashboardUiState
}

@HiltViewModel
class DashboardViewModel @Inject constructor(
    private val repository: AdminRepository
) : ActionViewModel() {

    private val _state = MutableStateFlow<DashboardUiState>(DashboardUiState.Loading)
    val state: StateFlow<DashboardUiState> = _state.asStateFlow()

    /** Role from the login payload — drives which quick actions appear. */
    val role: kotlinx.coroutines.flow.Flow<String?> = repository.userRoleFlow()

    /** Set once logout finished clearing tokens; the screen navigates away. */
    private val _loggedOut = MutableStateFlow(false)
    val loggedOut: StateFlow<Boolean> = _loggedOut.asStateFlow()

    /** True only for a pull-to-refresh, so the layout does not flash skeletons. */
    private val _refreshing = MutableStateFlow(false)
    val refreshing: StateFlow<Boolean> = _refreshing.asStateFlow()

    /** App version offer from the admin update channel (default admin). */
    private val _update = MutableStateFlow<AppVersion?>(null)
    val update: StateFlow<AppVersion?> = _update.asStateFlow()

    /** True when min_version_code > BuildConfig.VERSION_CODE. */
    private val _updateRequired = MutableStateFlow(false)
    val updateRequired: StateFlow<Boolean> = _updateRequired.asStateFlow()

    init {
        load()
        checkUpdate()
    }

    private fun checkUpdate() {
        viewModelScope.launch {
            repository.appVersion()
                .onSuccess { av ->
                    _update.value = av.takeIf { it.versionCode > BuildConfig.VERSION_CODE }
                    _updateRequired.value = av.minVersionCode > BuildConfig.VERSION_CODE
                }
                .onFailure { /* leave null — an update check must never break the dashboard */ }
        }
    }

    /**
     * Logout revokes both token rows server-side and clears local state
     * unconditionally — being offline must not trap an operator in a session
     * they asked to leave.
     */
    fun logout() {
        viewModelScope.launch {
            repository.logout()
            _loggedOut.value = true
        }
    }

    fun load() {
        viewModelScope.launch {
            _state.value = DashboardUiState.Loading
            repository.dashboard()
                .onSuccess { _state.value = DashboardUiState.Success(it) }
                .onFailure { _state.value = DashboardUiState.Error(it.message ?: "ত্রুটি হয়েছে") }
        }
    }

    /**
     * Pull-to-refresh keeps the current layout on screen: an operator should
     * not lose the numbers they are reading because they tugged the list.
     * A failure surfaces on the snackbar instead of blanking the screen.
     */
    public override fun refresh() {
        if (_refreshing.value) return
        viewModelScope.launch {
            _refreshing.value = true
            repository.dashboard()
                .onSuccess { _state.value = DashboardUiState.Success(it) }
                .onFailure { showSnack(it.message ?: "রিফ্রেশ ব্যর্থ হয়েছে") }
            _refreshing.value = false
        }
    }
}
