package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.AdminWithdraw
import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject

/**
 * The super-admin payout queue: list by status/search, and the same four
 * decisions the web desk offers on a row — approve, reject (with a reason,
 * which the service requires), claim and release. The service is the single
 * source of truth for who may do what; refusals arrive here as snackbars.
 */
@HiltViewModel
class WithdrawsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<AdminWithdraw>() {

    private val _detail = MutableStateFlow<AdminWithdraw?>(null)
    val detail: StateFlow<AdminWithdraw?> = _detail.asStateFlow()

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<AdminWithdraw>> =
        repository.withdraws(
            page = page,
            status = filter.ifBlank { null },
            query = query.ifBlank { null },
        )

    fun openDetail(row: AdminWithdraw) {
        _detail.value = row
    }

    fun closeDetail() {
        _detail.value = null
    }

    fun approve(row: AdminWithdraw) = decide(row) { repository.withdrawAction(row.id, "approve") }

    fun reject(row: AdminWithdraw, reason: String) = decide(row) {
        repository.withdrawAction(row.id, "reject", reason)
    }

    fun claim(row: AdminWithdraw) = decide(row) { repository.withdrawAction(row.id, "claim") }

    fun release(row: AdminWithdraw) = decide(row) { repository.withdrawAction(row.id, "release") }

    private fun decide(
        row: AdminWithdraw,
        block: suspend () -> Result<com.aliftools.admin.data.api.WithdrawEnvelope>,
    ) = runAction(
        messageOf = { it.message ?: "সিদ্ধান্ত সংরক্ষিত হয়েছে" },
        block = block,
        onSuccess = { fresh -> _detail.value = fresh.withdraw },
    )
}
