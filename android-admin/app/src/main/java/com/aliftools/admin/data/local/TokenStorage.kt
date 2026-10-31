package com.aliftools.admin.data.local

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "alif_admin_tokens")

@Singleton
class TokenStorage @Inject constructor(@ApplicationContext private val context: Context) {

    private val masterKey: MasterKey? by lazy {
        try {
            MasterKey.Builder(context)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()
        } catch (e: Exception) {
            Log.e(TAG, "Failed to create MasterKey", e)
            null
        }
    }

    private val encryptedPrefs: SharedPreferences? by lazy {
        val key = masterKey ?: return@lazy null
        try {
            EncryptedSharedPreferences.create(
                context,
                "alif_admin_secure_tokens",
                key,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
        } catch (e: Exception) {
            Log.e(TAG, "Failed to create EncryptedSharedPreferences, falling back to plain prefs", e)
            // Fallback to regular SharedPreferences to avoid crash on devices with keystore issues
            try {
                context.getSharedPreferences("alif_admin_secure_tokens_fallback", Context.MODE_PRIVATE)
            } catch (ex: Exception) {
                Log.e(TAG, "Failed to create fallback SharedPreferences", ex)
                null
            }
        }
    }

    private val accessTokenKey = "access_token"
    private val refreshTokenKey = "refresh_token"
    private val expiresAtKey = "expires_at"
    private val userRoleKey = "user_role"

    private val _accessToken = MutableStateFlow<String?>(null)
    private val _refreshToken = MutableStateFlow<String?>(null)
    private val _expiresAt = MutableStateFlow<String?>(null)
    private val _userRole = MutableStateFlow<String?>(null)

    init {
        // Hydrate the in-memory flows from the encrypted store once. ESP is
        // synchronous, so reading here (on the singleton's construction) is
        // safe and keeps the Flow API unchanged for callers.
        try {
            val prefs = encryptedPrefs
            _accessToken.value = prefs?.getString(accessTokenKey, null)
            _refreshToken.value = prefs?.getString(refreshTokenKey, null)
            _expiresAt.value = prefs?.getString(expiresAtKey, null)
            _userRole.value = prefs?.getString(userRoleKey, null)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to hydrate tokens from storage", e)
            _accessToken.value = null
            _refreshToken.value = null
            _expiresAt.value = null
            _userRole.value = null
        }
    }

    val accessTokenFlow: Flow<String?> = _accessToken.asStateFlow()
    val refreshTokenFlow: Flow<String?> = _refreshToken.asStateFlow()
    val userRoleFlow: Flow<String?> = _userRole.asStateFlow()

    // DataStore-backed (non-secret) values.
    private val biometricEnabled = stringPreferencesKey("biometric_enabled")
    private val rememberedIdentifier = stringPreferencesKey("remembered_identifier")

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
        try {
            encryptedPrefs?.edit {
                putString(accessTokenKey, token)
                putString(refreshTokenKey, refresh)
                putString(expiresAtKey, expiresAtStr ?: "")
                putString(userRoleKey, role)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to save tokens", e)
        }
        _accessToken.value = token
        _refreshToken.value = refresh
        _expiresAt.value = expiresAtStr ?: ""
        _userRole.value = role
    }

    suspend fun clearTokens() {
        try {
            encryptedPrefs?.edit {
                remove(accessTokenKey)
                remove(refreshTokenKey)
                remove(expiresAtKey)
                remove(userRoleKey)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to clear tokens", e)
        }
        _accessToken.value = null
        _refreshToken.value = null
        _expiresAt.value = null
        _userRole.value = null
    }

    private val SESSION_TIMEOUT_MILLIS = 15 * 60 * 1000L // 15 minutes

    suspend fun setBiometricEnabled(enabled: Boolean) {
        context.dataStore.edit { prefs ->
            prefs[biometricEnabled] = enabled.toString()
        }
    }

    /**
     * Calculates time remaining until token expiry.
     * Returns millis until expiry, or negative if already expired.
     */
    suspend fun timeUntilExpiry(): Long {
        val expiresAtStr = _expiresAt.value
        if (expiresAtStr.isNullOrBlank()) return -1L
        val expiresAt = parseExpiry(expiresAtStr) ?: return -1L
        return java.time.Duration.between(java.time.Instant.now(), expiresAt).toMillis()
    }

    /**
     * The API stamps `expires_at` as `Y-m-d H:i:s`, which `Instant.parse`
     * rejects outright. An unreadable timestamp has to read as "already
     * expired" rather than throw a DateTimeParseException out of a session
     * check, so both shapes are tried and neither may escape.
     */
    private fun parseExpiry(raw: String): java.time.Instant? =
        runCatching { java.time.Instant.parse(raw) }.getOrNull()
            ?: runCatching {
                java.time.LocalDateTime.parse(
                    raw,
                    java.time.format.DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"),
                ).atZone(java.time.ZoneId.systemDefault()).toInstant()
            }.getOrNull()

    /**
     * Returns whether the token is close to expiry (within the timeout window).
     */
    suspend fun isTokenExpiringSoon(): Boolean = timeUntilExpiry() < SESSION_TIMEOUT_MILLIS

    /**
     * Resets the expiry timer by updating expiresAt to current time + session duration.
     * Call this on user activity to extend the session.
     */
    suspend fun resetExpiry(): String {
        val instant = java.time.Instant.now().plus(java.time.Duration.ofMinutes(15))
        val newExpiry = java.time.format.DateTimeFormatter.ISO_INSTANT.format(instant)
        try {
            encryptedPrefs?.edit {
                putString(expiresAtKey, newExpiry)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Failed to reset expiry", e)
        }
        _expiresAt.value = newExpiry
        return newExpiry
    }

    companion object {
        private const val TAG = "TokenStorage"
    }
}

private fun android.content.SharedPreferences.edit(block: android.content.SharedPreferences.Editor.() -> Unit) {
    val editor = this.edit()
    block(editor)
    editor.apply()
}