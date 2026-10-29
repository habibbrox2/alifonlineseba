package com.aliftools.admin.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.aliftools.admin.ui.viewmodel.ListUiState

/**
 * The one list layout every paged admin screen uses: search field, filter
 * chips, refresh in the bar, and the four states a list can be in — first
 * load, empty, failed, and rows with a load-more button.
 *
 * Screens differ only in their row content, their filter set and what a row
 * taps into, all supplied by the caller.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun <T : Any> PagedListView(
    title: String,
    state: ListUiState<T>,
    onBack: () -> Unit,
    onRefresh: () -> Unit,
    onLoadMore: () -> Unit,
    onSearch: () -> Unit,
    onQueryChange: (String) -> Unit,
    onRetry: () -> Unit,
    busy: Boolean = false,
    snack: String? = null,
    onSnackShown: () -> Unit = {},
    onFilterChange: (String) -> Unit = {},
    filters: List<Pair<String, String>> = emptyList(),
    /** False for endpoints whose list route has no `q` parameter. */
    showSearch: Boolean = true,
    /** False when the list is a tab root: the bottom bar is the navigation. */
    showBack: Boolean = true,
    actions: @Composable RowScope.() -> Unit = {},
    itemKey: ((T) -> Any)? = null,
    emptyText: String = "কোনো ডেটা পাওয়া যায়নি",
    itemContent: @Composable (T) -> Unit,
) {
    val pullToRefreshState = rememberPullToRefreshState()
    AdminScaffold(
        title = title,
        onBack = onBack,
        snack = snack,
        onSnackShown = onSnackShown,
        showBack = showBack,
        actions = {
            actions()
            IconButton(onClick = onRefresh, enabled = !state.initialLoading) {
                Icon(Icons.Default.Refresh, contentDescription = "রিফ্রেশ করুন")
            }
        },
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding)) {
            if (busy) {
                LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            }

            if (showSearch) {
                OutlinedTextField(
                    value = state.query,
                    onValueChange = onQueryChange,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
                    placeholder = { Text("খুঁজুন…") },
                    singleLine = true,
                    trailingIcon = {
                        IconButton(onClick = onSearch) {
                            Icon(Icons.Default.Search, contentDescription = "খুঁজুন")
                        }
                    },
                )
            }

            if (filters.isNotEmpty()) {
                Row(
                    modifier = Modifier
                        .horizontalScroll(rememberScrollState())
                        .padding(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    filters.forEach { (value, label) ->
                        FilterChip(
                            selected = state.filter == value,
                            onClick = { onFilterChange(value) },
                            label = { Text(label) },
                        )
                    }
                }
            }

            if (state.error != null && state.rows.isNotEmpty()) {
                // The rows on screen are stale but usable; say so inline
                // instead of blanking the screen over a failed refresh.
                Text(
                    text = state.error,
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp),
                )
            }

            when {
                state.initialLoading -> {
                    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                }

                state.error != null && state.rows.isEmpty() -> {
                    Column(
                        modifier = Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) {
                        Text(
                            text = state.error,
                            color = MaterialTheme.colorScheme.error,
                            textAlign = TextAlign.Center,
                            modifier = Modifier.padding(horizontal = 32.dp),
                        )
                        Button(onClick = onRetry, modifier = Modifier.padding(top = 12.dp)) {
                            Text("পুনরায় চেষ্টা করুন")
                        }
                    }
                }

                state.rows.isEmpty() -> {
                    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        Text(
                            text = emptyText,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }

                else -> {
                    PullToRefreshBox(
                        isRefreshing = state.refreshing && !state.initialLoading,
                        onRefresh = onRefresh,
                        state = pullToRefreshState,
                        modifier = Modifier.fillMaxSize(),
                    ) {
                        LazyColumn(
                            modifier = Modifier.fillMaxSize(),
                            contentPadding = PaddingValues(16.dp),
                            verticalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            items(state.rows, key = itemKey) { row ->
                                itemContent(row)
                            }
                            if (state.hasMore) {
                                item(key = "load-more") {
                                    Button(
                                        onClick = onLoadMore,
                                        modifier = Modifier.fillMaxWidth(),
                                        enabled = !state.refreshing,
                                    ) {
                                        if (state.refreshing) {
                                            CircularProgressIndicator(
                                                modifier = Modifier.size(16.dp),
                                                strokeWidth = 2.dp,
                                            )
                                        } else {
                                            Text("আরও লোড করুন")
                                        }
                                    }
                                }
                            } else {
                                item(key = "list-end") {
                                    Text(
                                        text = "মোট ${state.total} টি",
                                        modifier = Modifier.fillMaxWidth(),
                                        textAlign = TextAlign.Center,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
