package com.aliftools.admin.ui.viewmodel

import com.aliftools.admin.data.model.PagedResponse
import com.aliftools.admin.data.model.User
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
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

    /** Set by a successful reset; the dialog reads it once and dismisses. */
    private val _temporaryPassword = MutableStateFlow<String?>(null)
    val temporaryPassword: StateFlow<String?> = _temporaryPassword.asStateFlow()

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<User>> =
        repository.staff(page = page, query = query.ifBlank { null })

    fun resetPassword(user: User) = runAction(
        refreshList = false,
        messageOf = { it.message ?: "পাসওয়ার্ড রিসেট হয়েছে" },
        block = { repository.staffAction("reset", user.id) },
        onSuccess = { _temporaryPassword.value = it.temporaryPassword },
    )

    fun dismissTemporaryPassword() {
        _temporaryPassword.value = null
    }
}
