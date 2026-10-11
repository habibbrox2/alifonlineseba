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
 * Users list: paged search over /api/admin/users plus the same row actions
 * the web panel offers — toggle status, change role, reset to a temporary
 * password, soft-delete and restore.
 */
@HiltViewModel
class UsersViewModel @Inject constructor(
    private val repository: AdminRepository,
) : PagedViewModel<User>() {

    /** Set by a successful reset; the dialog reads it once and dismisses. */
    private val _temporaryPassword = MutableStateFlow<String?>(null)
    val temporaryPassword: StateFlow<String?> = _temporaryPassword.asStateFlow()

    /** True once "create" succeeded so the dialog can close itself. */
    private val _created = MutableStateFlow(false)
    val created: StateFlow<Boolean> = _created.asStateFlow()

    override suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<User>> =
        repository.users(
            page = page,
            query = query.ifBlank { null },
            // "" (the default chip) means live accounts only — the backend's
            // DELETED_EXCLUDE; "only" is the trash, "all" is everything.
            trashed = filter.ifBlank { null },
        )

    fun toggleStatus(user: User) = runAction(
        messageOf = { it.message ?: "ইউজার আপডেট হয়েছে" },
        block = { repository.userAction("toggle", user.id) },
    )

    fun changeRole(user: User, role: String) = runAction(
        messageOf = { it.message ?: "রোল আপডেট হয়েছে" },
        block = { repository.userAction("role", user.id, mapOf("role" to role)) },
    )

    fun resetPassword(user: User) = runAction(
        // No refresh: the list row does not change, the dialog does.
        refreshList = false,
        messageOf = { it.message ?: "পাসওয়ার্ড রিসেট হয়েছে" },
        block = { repository.userAction("reset", user.id) },
        onSuccess = { _temporaryPassword.value = it.temporaryPassword },
    )

    fun deleteUser(user: User) = runAction(
        messageOf = { it.message ?: "ইউজার ট্র্যাশে পাঠানো হয়েছে" },
        block = { repository.userAction("delete", user.id) },
    )

    fun restoreUser(user: User) = runAction(
        messageOf = { it.message ?: "ইউজার ফিরিয়ে আনা হয়েছে" },
        block = { repository.userAction("restore", user.id) },
    )

    fun createUser(username: String, phone: String, password: String, role: String, email: String) =
        runAction(
            messageOf = { it.message ?: "ইউজার তৈরি হয়েছে" },
            block = {
                repository.userAction(
                    action = "create",
                    extra = buildMap {
                        put("username", username)
                        put("phone", phone)
                        put("password", password)
                        put("role", role)
                        if (email.isNotBlank()) put("email", email)
                    },
                )
            },
            onSuccess = { _created.value = it.userId != null },
        )

    fun dismissTemporaryPassword() {
        _temporaryPassword.value = null
    }

    fun dismissCreated() {
        _created.value = false
    }
}
