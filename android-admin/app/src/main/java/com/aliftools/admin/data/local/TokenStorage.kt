package com.aliftools.admin.data.local

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "alif_admin_tokens")

@Singleton
class TokenStorage @Inject constructor(@ApplicationContext private val context: Context) {

    private val accessToken = stringPreferencesKey("access_token")
    private val refreshToken = stringPreferencesKey("refresh_token")
    private val expiresAt = stringPreferencesKey("expires_at")
    private val userRole = stringPreferencesKey("user_role")
    private val biometricEnabled = stringPreferencesKey("biometric_enabled")

    // "Remember me" persists the identifier only — never the password. A
    // shared or lost shop phone must not hand the next person a working
    // credential, so the field is pre-filled and nothing more.
    private val rememberedIdentifier = stringPreferencesKey("remembered_identifier")

    val accessTokenFlow: Flow<String?> = context.dataStore.data
        .map { it[accessToken] }

    val refreshTokenFlow: Flow<String?> = context.dataStore.data
        .map { it[refreshToken] }

    val userRoleFlow: Flow<String?> = context.dataStore.data
        .map { it[userRole] }

    val biometricEnabledFlow: Flow<Boolean> = context.dataStore.data
        .map { it[biometricEnabled]?.toBoolean() ?: false }

    /** The identifier saved by "remember me", or null when it is switched off. */
    val rememberedIdentifierFlow: Flow<String?> = context.dataStore.data
        .map { it[rememberedIdentifier] }

    /**
     * Saves the identifier to pre-fill next time. Passing null (or unchecking
     * the box) forgets it, so a shared device can be cleared without touching
     * the session tokens.
     */
    suspend fun setRememberedIdentifier(identifier: String?) {
        context.dataStore.edit { prefs ->
            if (identifier.isNullOrBlank()) {
                prefs.remove(rememberedIdentifier)
            } else {
                prefs[rememberedIdentifier] = identifier
            }
        }
    }

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
