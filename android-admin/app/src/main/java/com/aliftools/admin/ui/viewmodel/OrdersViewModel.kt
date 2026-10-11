package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.AdminOrder
import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import javax.inject.Inject

/**
 * Orders list with the scope tabs the web desk has (all / mine / unclaimed)
 * and row selection feeding the bulk settle job — POST /api/admin/orders
 * with do=bulk_status, the same endpoint the browser panel posts to.
 */
@HiltViewModel
class OrdersViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<AdminOrder>() {

    private val _selected = MutableStateFlow<Set<Int>>(emptySet())
    val selected: StateFlow<Set<Int>> = _selected.asStateFlow()

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<AdminOrder>> =
        repository.orders(
            page = page,
            query = query.ifBlank { null },
            // filter carries the scope: "" -> backend default (all),
            // "mine" -> only my rows, "taken" -> only unclaimed rows.
            scope = filter.ifBlank { null },
        )

    fun toggleSelected(id: Int) = _selected.update {
        if (id in it) it - id else it + id
    }

    fun clearSelection() {
        _selected.value = emptySet()
    }

    fun settleSelected(status: String) {
        val ids = _selected.value.toList()
        if (ids.isEmpty()) return
        runAction(
            messageOf = { it.message ?: "সেটেল করার কাজ কিউতে পাঠানো হয়েছে" },
            block = { repository.bulkStatus(ids, status) },
            onSuccess = { _selected.value = emptySet() },
        )
    }
}
