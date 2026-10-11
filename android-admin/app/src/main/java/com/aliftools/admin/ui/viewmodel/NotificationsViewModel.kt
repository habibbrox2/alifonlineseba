package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.AdminNotification
import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject

/**
 * Notification queue: status filter plus the retry action, which the
 * backend accepts only for dead-letter rows (POST /{id}/retry).
 */
@HiltViewModel
class NotificationsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<AdminNotification>() {

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<AdminNotification>> =
        repository.notifications(page = page, status = filter.ifBlank { null })

    fun retry(row: AdminNotification) = runAction(
        messageOf = { it.message ?: "অনুরোধটি আবার কিউতে পাঠানো হয়েছে" },
        block = { repository.retryNotification(row.id) },
    )
}
