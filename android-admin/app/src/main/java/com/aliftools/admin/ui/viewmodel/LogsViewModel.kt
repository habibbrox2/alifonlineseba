package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.ActivityLog
import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject

/** Activity log — read-only, searchable over action/description/ip. */
@HiltViewModel
class LogsViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<ActivityLog>() {

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<ActivityLog>> =
        repository.logs(page = page, query = query.ifBlank { null })
}
