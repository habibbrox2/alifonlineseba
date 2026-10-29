package com.aliftools.admin.ui.viewmodel

import androidx.lifecycle.viewModelScope
import com.aliftools.admin.data.model.AdminOrder
import com.aliftools.admin.data.model.OrderDetailEnvelope
import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

/**
 * Orders list with the scope tabs the web desk has (all / mine / unclaimed),
 * row selection feeding the bulk settle job, and full order inspection & review
 * desk (claim, release, approve, reject with refund, and status updates).
 */
@HiltViewModel
class OrdersViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<AdminOrder>() {

    private val _selected = MutableStateFlow<Set<Int>>(emptySet())
    val selected: StateFlow<Set<Int>> = _selected.asStateFlow()

    private val _detail = MutableStateFlow<OrderDetailEnvelope?>(null)
    val detail: StateFlow<OrderDetailEnvelope?> = _detail.asStateFlow()

    private val _loadingDetail = MutableStateFlow(false)
    val loadingDetail: StateFlow<Boolean> = _loadingDetail.asStateFlow()

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<AdminOrder>> =
        repository.orders(
            page = page,
            query = query.ifBlank { null },
            scope = filter.ifBlank { null },
        )

    fun toggleSelected(id: Int) = _selected.update {
        if (id in it) it - id else it + id
    }

    fun clearSelection() {
        _selected.value = emptySet()
    }

    fun openDetail(id: Int) {
        viewModelScope.launch {
            _loadingDetail.value = true
            repository.order(id)
                .onSuccess { envelope ->
                    _detail.value = envelope
                }
                .onFailure { error ->
                    showSnack(error.message ?: "অর্ডার বিবরণ লোড করা যায়নি")
                }
            _loadingDetail.value = false
        }
    }

    fun closeDetail() {
        _detail.value = null
    }

    fun claim(id: Int) = runDetailAction(id) {
        repository.orderAction(id = id, action = "claim")
    }

    fun release(id: Int) = runDetailAction(id) {
        repository.orderAction(id = id, action = "release")
    }

    fun approve(id: Int, note: String = "") = runDetailAction(id) {
        repository.orderAction(id = id, action = "approve", adminNote = note.ifBlank { null })
    }

    fun reject(id: Int, reason: String, note: String = "") = runDetailAction(id) {
        repository.orderAction(
            id = id,
            action = "reject",
            status = "cancelled",
            reason = reason.ifBlank { null },
            adminNote = note.ifBlank { null },
        )
    }

    fun setStatus(id: Int, status: String, note: String = "") = runDetailAction(id) {
        repository.orderAction(id = id, action = "status", status = status, adminNote = note.ifBlank { null })
    }

    private fun runDetailAction(
        id: Int,
        block: suspend () -> Result<com.aliftools.admin.data.api.ActionResponse>,
    ) {
        runAction(
            messageOf = { it.message ?: "অর্ডার অ্যাকশন সম্পন্ন হয়েছে" },
            block = block,
            onSuccess = {
                openDetail(id)
                refresh()
            },
        )
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

    fun settleSelectedStatus(id: Int, status: String) {
        runAction(
            messageOf = { it.message ?: "অর্ডার আপডেট করা হয়েছে" },
            block = { repository.bulkStatus(listOf(id), status) },
        )
    }
}
