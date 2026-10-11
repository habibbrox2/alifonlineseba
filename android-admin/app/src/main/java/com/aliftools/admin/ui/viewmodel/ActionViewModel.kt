package com.aliftools.admin.ui.viewmodel

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * The action half of a screen's state: a busy flag that dims mutating
 * controls and a one-shot snackbar message. Split out from PagedViewModel so
 * non-list screens (Settings) share the same behaviour without pretending to
 * be a paged list.
 */
abstract class ActionViewModel : ViewModel() {

    /** True while a mutating action (approve, save, …) is in flight. */
    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()

    /** One-shot message for the snackbar; null once consumed. */
    private val _snack = MutableStateFlow<String?>(null)
    val snack: StateFlow<String?> = _snack.asStateFlow()

    fun showSnack(message: String) {
        _snack.value = message
    }

    fun clearSnack() {
        _snack.value = null
    }

    /**
     * Runs a mutating action with the shared busy flag, puts [messageOf]'s
     * text (or the failure message) on the snackbar, and refreshes afterwards
     * unless [refreshList] says not to.
     */
    protected fun <R> runAction(
        refreshList: Boolean = true,
        messageOf: (R) -> String,
        block: suspend () -> Result<R>,
        onSuccess: (R) -> Unit = {},
        onFailure: (Throwable) -> Unit = {},
        onDone: () -> Unit = {},
    ) {
        if (_busy.value) return
        viewModelScope.launch {
            _busy.value = true
            try {
                block()
                    .onSuccess { result ->
                        messageOf(result).takeIf { it.isNotBlank() }?.let { _snack.value = it }
                        onSuccess(result)
                        if (refreshList) refresh()
                    }
                    .onFailure { error ->
                        _snack.value = error.message ?: "কাজ ব্যর্থ হয়েছে"
                        onFailure(error)
                    }
            } finally {
                _busy.value = false
                onDone()
            }
        }
    }

    /** What a successful action re-reads. Lists reload; forms reload too. */
    protected open fun refresh() {}
}
