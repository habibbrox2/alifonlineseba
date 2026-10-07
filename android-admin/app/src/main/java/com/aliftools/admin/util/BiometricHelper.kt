package com.aliftools.admin.util

import android.content.Context
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.activity.ComponentActivity
import javax.inject.Inject

class BiometricHelper @Inject constructor(private val context: Context) {

    fun isBiometricAvailable(): Int {
        val manager = BiometricManager.from(context)
        return manager.canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL)
    }

    fun showPrompt(
        activity: ComponentActivity,
        onSuccess: () -> Unit,
        onFailure: () -> Unit
    ) {
        val promptInfo = BiometricPrompt.PromptInfo.Builder()
            .setTitle("অথেন্টিকেশন")
            .setSubtitle("এই অপারেশন সক্রিয় করতে আপনার বিয়োমেট্রিক্স ব্যবহার করুন")
            .setNegativeButtonText("বাতিল")
            .build()

        val biometricPrompt = BiometricPrompt(activity, ContextCompat.getMainExecutor(context),
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                    super.onAuthenticationSucceeded(result)
                    onSuccess()
                }

                override fun onAuthenticationFailed() {
                    super.onAuthenticationFailed()
                    onFailure()
                }

                override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                    super.onAuthenticationError(errorCode, errString)
                    onFailure()
                }
            })

        biometricPrompt.authenticate(promptInfo)
    }
}
