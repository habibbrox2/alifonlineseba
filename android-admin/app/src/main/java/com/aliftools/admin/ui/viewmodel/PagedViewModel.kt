package com.aliftools.admin.ui.viewmodel

import androidx.lifecycle.viewModelScope
import com.aliftools.admin.data.model.PagedResponse
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * State shared by every paged admin list: one accumulated page of rows plus
 * the flags that decide which placeholder the screen shows.
 */
data class ListUiState<T>(
    val initialLoading: Boolean = true,
    val refreshing: Boolean = false,
    val error: String? = null,
    val rows: List<T> = emptyList(),
    val page: Int = 1,
    val total: Int = 0,
    val hasMore: Boolean = false,
    val query: String = "",
    val filter: String = "",
)

/**
 * Base class for the list screens. A subclass only says how to fetch one
 * page; reload, pagination, search and filter live here so the eight list
 * screens cannot drift apart. Busy/snackbar behaviour comes from
 * [ActionViewModel].
 */
abstract class PagedViewModel<T : Any> : ActionViewModel() {

    private val _state = MutableStateFlow(ListUiState<T>())
    val state: StateFlow<ListUiState<T>> = _state.asStateFlow()

    private var inFlight = false

    init {
        reload()
    }

    /** Fetch one page from the repository. Query/filter come from state. */
    protected abstract suspend fun fetch(page: Int, query: String, filter: String): Result<PagedResponse<T>>

    fun reload() = loadPage(1)

    fun retry() = reload()

    fun loadMore() {
        if (!_state.value.hasMore) return
        loadPage(_state.value.page + 1)
    }

    fun onQueryChange(value: String) = _state.update { it.copy(query = value) }

    /** IME search action or the magnifier button. */
    fun search() = reload()

    fun onFilterChange(value: String) {
        if (_state.value.filter == value) return
        _state.update { it.copy(filter = value) }
        reload()
    }

    override fun refresh() = reload()

    private fun loadPage(page: Int) {
        if (inFlight) return
        inFlight = true
        viewModelScope.launch {
            _state.update {
                it.copy(
                    initialLoading = it.rows.isEmpty(),
                    refreshing = it.rows.isNotEmpty(),
                    error = null,
                )
            }
            fetch(page, _state.value.query, _state.value.filter)
                .onSuccess { paged ->
                    _state.update { cur ->
                        val newRows = paged.rows ?: emptyList()
                        val merged = if (page == 1) newRows else cur.rows + newRows
                        cur.copy(
                            initialLoading = false,
                            refreshing = false,
                            error = null,
                            rows = merged,
                            page = paged.page.coerceAtLeast(1),
                            total = paged.total,
                            hasMore = merged.size < paged.total,
                        )
                    }
                }
                .onFailure { error ->
                    _state.update {
                        it.copy(
                            initialLoading = false,
                            refreshing = false,
                            error = error.message ?: "লোড ব্যর্থ হয়েছে",
                        )
                    }
                }
            inFlight = false
        }
    }
}
