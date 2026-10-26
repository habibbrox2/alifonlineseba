package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltViewModel
class CategoriesViewModel @Inject constructor(
    private val repo: AdminRepository,
) : ActionViewModel() {

    private val _rows = MutableStateFlow<List<Map<String, Any?>>>(emptyList())
    val rows: StateFlow<List<Map<String, Any?>>> = _rows.asStateFlow()

    private val _loading = MutableStateFlow(false)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            _loading.value = true
            repo.categories()
                .onSuccess { data ->
                    val list = data["rows"] as? List<*> ?: emptyList()
                    _rows.value = list.filterIsInstance<Map<String, Any?>>()
                }
                .onFailure { err ->
                    showSnack(err.message ?: "লোড ব্যর্থ হয়েছে")
                }
            _loading.value = false
        }
    }

    fun create(name: String, slug: String) {
        runAction(
            refreshList = false,
            messageOf = { it.message ?: "তৈরি হয়েছে" },
            block = { repo.categoriesAction("create", name = name, slug = slug) },
            onSuccess = { load() },
        )
    }

    fun toggle(id: Int) {
        runAction(
            refreshList = false,
            messageOf = { it.message ?: "হালনাগাদ হয়েছে" },
            block = { repo.categoriesAction("toggle", id = id) },
            onSuccess = { load() },
        )
    }

    fun delete(id: Int) {
        runAction(
            refreshList = false,
            messageOf = { it.message ?: "মুছে ফেলা হয়েছে" },
            block = { repo.categoriesAction("delete", id = id) },
            onSuccess = { load() },
        )
    }
}
