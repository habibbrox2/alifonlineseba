package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class RoleHierarchyViewModel @Inject constructor(
    private val repo: AdminRepository,
) : ActionViewModel() {

    private val _data = MutableStateFlow<Map<String, Any?>>(emptyMap())
    val data: StateFlow<Map<String, Any?>> = _data.asStateFlow()

    private val _loading = MutableStateFlow(false)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _loading.value = true
            repo.roleHierarchy()
                .onSuccess { _data.value = it }
                .onFailure { err -> setSnack(err.message ?: "লোড ব্যর্থ হয়েছে") }
            _loading.value = false
        }
    }
}
