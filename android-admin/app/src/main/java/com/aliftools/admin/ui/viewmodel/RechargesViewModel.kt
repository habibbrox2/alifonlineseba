package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.AdminTopup
import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject

/**
 * Recharge queue. The list endpoint takes page + status (its `q` search is
 * server-side only for withdraws, so this screen offers status chips, not a
 * search box), and each row decides through the detail action endpoint —
 * do=approve, or do=reject with a note.
 */
@HiltViewModel
class RechargesViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<AdminTopup>() {

    /** The row whose decision dialog is open, if any. */
    private val _detail = MutableStateFlow<AdminTopup?>(null)
    val detail: StateFlow<AdminTopup?> = _detail.asStateFlow()

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<AdminTopup>> =
        repository.topups(page = page, status = filter.ifBlank { null })

    fun openDetail(row: AdminTopup) {
        _detail.value = row
    }

    fun closeDetail() {
        _detail.value = null
    }

    fun approve(row: AdminTopup) = runAction(
        messageOf = { it.message ?: "রিচার্জ অনুমোদিত হয়েছে" },
        block = { repository.rechargeAction(row.id, "approve") },
        onSuccess = { _detail.value = null },
    )

    fun reject(row: AdminTopup, note: String) = runAction(
        messageOf = { it.message ?: "রিচার্জ বাতিল করা হয়েছে" },
        block = { repository.rechargeAction(row.id, "reject", note) },
        onSuccess = { _detail.value = null },
    )
}
