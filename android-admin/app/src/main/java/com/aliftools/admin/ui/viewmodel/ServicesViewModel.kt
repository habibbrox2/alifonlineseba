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
class ServicesViewModel @Inject constructor(
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
            try {
                val result: Result<Map<String, Any?>> = repo.services()
                result.fold(
                    onSuccess = { data: Map<String, Any?> ->
                        val list: List<*> = data["rows"] as? List<*> ?: emptyList<Any?>()
                        _rows.value = list.filterIsInstance<Map<String, Any?>>()
                    },
                    onFailure = { error: Throwable ->
                        showSnack(error.message ?: "লোড ব্যর্থ হয়েছে")
                    },
                )
            } finally {
                _loading.value = false
            }
        }
    }

    /** Create a new service, then reload the list. */
    fun create(
        name: String,
        slug: String,
        categoryId: Int?,
        price: String?,
        unit: String?,
        description: String?,
        sortOrder: Int?,
        status: String?,
    ) {
        runAction(
            refreshList = false,
            messageOf = { it.message ?: "তৈরি হয়েছে" },
            block = { repo.servicesAction(
                "create",
                name = name,
                slug = slug,
                categoryId = categoryId,
                price = price,
                unit = unit,
                description = description,
                sortOrder = sortOrder,
                status = status,
            ) },
            onSuccess = { load() },
        )
    }

    /** Edit an existing service, then reload the list. */
    fun edit(
        id: Int,
        name: String,
        slug: String,
        categoryId: Int?,
        price: String?,
        unit: String?,
        description: String?,
        sortOrder: Int?,
        status: String?,
    ) {
        runAction(
            refreshList = false,
            messageOf = { it.message ?: "হালনাগাদ হয়েছে" },
            block = { repo.servicesAction(
                "edit",
                id = id,
                name = name,
                slug = slug,
                categoryId = categoryId,
                price = price,
                unit = unit,
                description = description,
                sortOrder = sortOrder,
                status = status,
            ) },
            onSuccess = { load() },
        )
    }

    fun toggle(id: Int) {
        runAction(
            refreshList = false,
            messageOf = { it.message ?: "হালনাগাদ হয়েছে" },
            block = { repo.servicesAction("toggle", id = id) },
            onSuccess = { load() },
        )
    }

    fun delete(id: Int) {
        runAction(
            refreshList = false,
            messageOf = { it.message ?: "মুছে ফেলা হয়েছে" },
            block = { repo.servicesAction("delete", id = id) },
            onSuccess = { load() },
        )
    }
}
