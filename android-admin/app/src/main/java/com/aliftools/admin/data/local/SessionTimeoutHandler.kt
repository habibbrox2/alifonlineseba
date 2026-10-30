package com.aliftools.admin.data.local

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.preferencesDataStore
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.isActive
import javax.inject.Inject
import javax.inject.Singleton

const val SESSION_TIMEOUT_MILLIS = 15 * 60 * 1000L // 15 minutes

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "session_prefs")

/**
 * Watches for user activity and fires [onSessionExpired] after a period of
 * inactivity. Runs on [Dispatchers.Default] so it never crashes when started
 * from a non-UI thread; the callback is responsible for marshalling back to
 * the UI thread if it touches Compose state.
 */
@Singleton
class SessionTimeoutHandler @Inject constructor(
    @ApplicationContext private val context: Context,
    private val repository: AdminRepository,
    private val onSessionExpired: () -> Unit,
    private val onActivity: () -> Unit,
) {
    private val scope = CoroutineScope(Dispatchers.Default)
    private var timeoutJob: Job? = null

    /** Start the session timeout timer. Safe to call from any thread. */
    fun start() {
        reset()
    }

    /** Stop the timeout timer. Safe to call from any thread. */
    fun stop() {
        timeoutJob?.cancel()
        timeoutJob = null
    }

    /** Reset the timeout timer (call on user activity). Safe to call from any thread. */
    fun reset() {
        timeoutJob?.cancel()
        timeoutJob = scope.launch {
            delay(SESSION_TIMEOUT_MILLIS)
            if (isActive) {
                onSessionExpired()
            }
        }
        onActivity()
    }
}
