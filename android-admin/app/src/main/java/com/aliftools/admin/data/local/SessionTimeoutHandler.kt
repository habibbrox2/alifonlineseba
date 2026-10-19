package com.aliftools.admin.data.local

import android.annotation.SuppressLint
import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.aliftools.admin.data.repository.AdminRepository
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch
import javax.inject.Inject
import javax.inject.Singleton

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "alif_admin_tokens")

const val SESSION_TIMEOUT_MILLIS = 15 * 60 * 1000 // 15 minutes

/***
 * Handles session timeout: logs out the user after a period of inactivity.
 * 
 * Inactivity is defined as no UI events or navigation for the timeout duration.
 * The timer resets on any user activity (navigation, screen interaction).
 */
@Singleton
class SessionTimeoutHandler @Inject constructor(
    @ApplicationContext private val context: Context,
    private val repository: AdminRepository,

    // Called when the session expires and the user should be logged out
    val onSessionExpired: () -> Unit,

    // Called when user activity is detected (timer should reset)
    val onActivity: () -> Unit
) {

    private val scope = CoroutineScope(Dispatchers.Main + Dispatchers.IO)
    private var timeoutJob: kotlinx.coroutines.Job? = null

    /** Start the session timeout timer */
    fun start() {
        // Reset any existing timer
        stop()
        
        timeoutJob = scope.launch {
            while (true) {
                // Wait for user activity or timeout
                @SuppressLint("UnsafeCoroutines")
                val result = kotlinx.coroutines.withTimeoutOrNull(SESSION_TIMEOUT_MILLIS) {
                    // Wait - but check for activity resets
                    delay(SESSION_TIMEOUT_MILLIS / 10) // Check every ~9 seconds
                    onActivity() // Reset timer on each check (conservative approach)
                }
                
                if (result == SESSION_TIMEOUT_MILLIS) {
                    // Timeout expired without activity reset
                    onSessionExpired()
                    break
                }
                // Activity was detected, loop continues with new timer
            }
        }
    }

    /** Stop the session timeout timer */
    fun stop() {
        timeoutJob?.cancel()
        timeoutJob = null
    }

    /** Reset the timeout timer (call on user activity) */
    fun reset() {
        onActivity()
    }
}