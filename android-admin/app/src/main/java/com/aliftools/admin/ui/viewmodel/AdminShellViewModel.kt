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

    /** Null while the token store has not answered yet; true/false once known. */
    val authed: StateFlow<Boolean?> = repository.accessTokenFlow()
        .map { !it.isNullOrBlank() }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), false)

    /** The role stored at login, or null while unknown. */
    val role: StateFlow<String?> = repository.userRoleFlow()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), null)

    /** One-shot logout flag. Set by [logout], consumed by [AdminApp]. */
    private val _loggedOut = MutableStateFlow(false)
    val loggedOut: StateFlow<Boolean> = _loggedOut.asStateFlow()

    /** Call after the shell has reacted to logout, to re-arm the flag. */
    fun consumeLoggedOut() {
        _loggedOut.value = false
    }

    /** Clears stored tokens and signals the shell to navigate to login. */
    fun logout() {
        viewModelScope.launch {
            repository.logout()
        }
        _loggedOut.value = true
    }
}
