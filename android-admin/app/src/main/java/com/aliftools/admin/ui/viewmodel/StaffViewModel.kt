package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.model.User
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject

/**
 * Staff roster — a superadmin-only read view over /api/admin/staff.
 * Role changes happen through the users endpoint on the Users screen, so
 * this list stays deliberately action-free.
 */
@HiltViewModel
class StaffViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<User>() {

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<User>> =
        repository.staff(page = page, query = query.ifBlank { null })
}
