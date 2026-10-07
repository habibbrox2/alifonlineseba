package com.aliftools.admin.data.local

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "alif_admin_tokens")

@Singleton
class TokenStorage @Inject constructor(private val context: Context) {

    private val accessToken = stringPreferencesKey("access_token")
    private val refreshToken = stringPreferencesKey("refresh_token")
    private val expiresAt = stringPreferencesKey("expires_at")
    private val userRole = stringPreferencesKey("user_role")
    private val biometricEnabled = stringPreferencesKey("biometric_enabled")

    val accessTokenFlow: Flow<String?> = context.dataStore.data
        .map { it[accessToken] }

    val refreshTokenFlow: Flow<String?> = context.dataStore.data
        .map { it[refreshToken] }

    val userRoleFlow: Flow<String?> = context.dataStore.data
        .map { it[userRole] }

    val biometricEnabledFlow: Flow<Boolean> = context.dataStore.data
        .map { it[biometricEnabled]?.toBoolean() ?: false }

    suspend fun saveTokens(token: String, refresh: String, expiresAtStr: String?, role: String) {
        context.dataStore.edit { prefs ->
            prefs[accessToken] = token
            prefs[refreshToken] = refresh
            prefs[expiresAt] = expiresAtStr ?: ""
            prefs[userRole] = role
        }
    }

    suspend fun clearTokens() {
        context.dataStore.edit { prefs ->
            prefs.remove(accessToken)
            prefs.remove(refreshToken)
            prefs.remove(expiresAt)
            prefs.remove(userRole)
        }
    }

    suspend fun setBiometricEnabled(enabled: Boolean) {
        context.dataStore.edit { prefs ->
            prefs[biometricEnabled] = enabled.toString()
        }
    }
}
