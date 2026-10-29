package com.aliftools.admin.ui.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import javax.inject.Inject

/**
 * Session state for the app shell: whether a token exists (splash routing) and
 * which role drives the tab set. Both come from one store, so the bar and the
 * splash can never disagree about who is signed in.
 */
@HiltViewModel
class AdminShellViewModel @Inject constructor(
    private val repository: AdminRepository,
) : ViewModel() {

    /** Null while the token store has not answered yet. */
    val authed: StateFlow<Boolean?> = repository.accessTokenFlow()
        .map { !it.isNullOrBlank() }
        .stateIn(viewModelScope, SharingStarted.Eagerly, null)

    val role: StateFlow<String?> = repository.userRoleFlow()
        .stateIn(viewModelScope, SharingStarted.Eagerly, null)

    private val _loggedOut = MutableStateFlow(false)
    val loggedOut: StateFlow<Boolean> = _loggedOut.asStateFlow()

    fun logout() {
        viewModelScope.launch {
            repository.logout()
            _loggedOut.value = true
        }
    }

    fun consumeLoggedOut() {
        _loggedOut.value = false
    }
}
