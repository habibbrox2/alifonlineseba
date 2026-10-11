package com.aliftools.admin.ui.auth

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.auth.FirebaseAuth
import com.google.firebase.auth.PhoneAuthCredential
import com.google.firebase.auth.PhoneAuthOptions
import com.google.firebase.auth.PhoneAuthProvider
import java.util.concurrent.TimeUnit

/**
 * Firebase phone sign-in for the admin app.
 *
 * Deliberately tolerant of a missing Firebase app. The APK builds and runs
 * fine without a `google-services.json` — messaging already degrades that way
 * — and this helper follows the same contract: when there is no Firebase app,
 * [isAvailable] is false and the login screen hides the button instead of
 * offering a control that can only fail. A half-configured build must never
 * look broken to the operator holding it.
 *
 * The flow is Play Services' usual one: `verifyPhoneNumber` either auto-verifies
 * on this device (SIM present, Play Services can vouch) and hands back a
 * credential straight away, or falls back to an SMS the user types in. Only the
 * credential's ID token ever leaves the device; no phone number is posted.
 */
class FirebasePhoneSignIn(private val activity: Activity) {

    /** Set once the SMS fallback has a verification id to code against. */
    private var verificationId: String? = null

    private val auth: FirebaseAuth?
        get() = try {
            if (FirebaseApp.getApps(activity).isEmpty()) null
            else FirebaseAuth.getInstance()
        } catch (e: Exception) {
            Log.w(TAG, "Firebase is not configured in this build", e)
            null
        }

    /**
     * Whether this build has a usable Firebase app at all.
     *
     * Checked without throwing so the login screen can decide to hide its
     * button at composition time.
     */
    fun isAvailable(): Boolean = try {
        FirebaseApp.getApps(activity).isNotEmpty()
    } catch (e: Exception) {
        false
    }

    /**
     * Starts phone verification.
     *
     * @param onToken   called on the main thread with a Firebase ID token, the
     *                  moment the user is proven to hold the number.
     * @param onCodeSent called when an SMS was sent and the user must type the
     *                  code — pair with [signInWithCode].
     * @param onError   called with a human-readable reason on any failure.
     */
    fun start(
        phoneNumber: String,
        onToken: (String) -> Unit,
        onCodeSent: (verificationId: String) -> Unit,
        onError: (String) -> Unit,
    ) {
        val firebase = auth
        if (firebase == null) {
            onError("ফায়ারবেজ কনফিগার করা নেই। অ্যাপ আপডেট করুন।")
            return
        }

        val callbacks = object : PhoneAuthProvider.OnVerificationStateChangedCallbacks() {
            override fun onVerificationCompleted(credential: PhoneAuthCredential) {
                // Auto-retrieval or instant verification: no user input needed.
                exchange(credential, onToken, onError)
            }

            override fun onVerificationFailed(e: com.google.firebase.FirebaseException) {
                Log.w(TAG, "Phone verification failed", e)
                onError(e.message ?: "ফোন যাচাই ব্যর্থ হয়েছে।")
            }

            override fun onCodeSent(id: String, token: PhoneAuthProvider.ForceResendingToken) {
                verificationId = id
                onCodeSent(id)
            }
        }

        try {
            val options = PhoneAuthOptions.newBuilder(firebase)
                .setPhoneNumber(phoneNumber)
                .setTimeout(60L, TimeUnit.SECONDS)
                .setActivity(activity)
                .setCallbacks(callbacks)
                .build()
            PhoneAuthProvider.verifyPhoneNumber(options)
        } catch (e: Exception) {
            Log.w(TAG, "Could not start phone verification", e)
            onError(e.message ?: "ফোন যাচাই শুরু করা যায়নি।")
        }
    }

    /** Completes the SMS fallback with the six-digit code the user typed. */
    fun signInWithCode(code: String, onToken: (String) -> Unit, onError: (String) -> Unit) {
        val id = verificationId
        if (id.isNullOrBlank()) {
            onError("যাচাইকরণ কোড পাওয়া যায়নি। আবার চেষ্টা করুন।")
            return
        }
        val firebase = auth
        if (firebase == null) {
            onError("ফায়ারবেজ কনফিগার করা নেই।")
            return
        }
        exchange(PhoneAuthProvider.getCredential(id, code), onToken, onError)
    }

    /**
     * Swaps a credential for an ID token.
     *
     * The token is what the backend verifies; the phone number itself is never
     * sent over the wire by this app.
     */
    private fun exchange(
        credential: PhoneAuthCredential,
        onToken: (String) -> Unit,
        onError: (String) -> Unit,
    ) {
        val firebase = auth ?: return onError("ফায়ারবেজ কনফিগার করা নেই।")
        firebase.signInWithCredential(credential)
            .addOnSuccessListener { result ->
                val user = result.user
                if (user == null) {
                    onError("সাইন ইন ব্যর্থ হয়েছে।")
                    return@addOnSuccessListener
                }
                user.getIdToken(false)
                    .addOnSuccessListener { res -> onToken(res.token.orEmpty()) }
                    .addOnFailureListener { e ->
                        Log.w(TAG, "Could not mint ID token", e)
                        onError(e.message ?: "টোকেন তৈরি করা যায়নি।")
                    }
            }
            .addOnFailureListener { e ->
                Log.w(TAG, "Credential sign-in failed", e)
                onError(e.message ?: "সাইন ইন ব্যর্থ হয়েছে।")
            }
    }

    /** Clears any in-flight verification so a retry starts clean. */
    fun reset() {
        verificationId = null
    }

    companion object {
        private const val TAG = "AlifAdminPhoneAuth"

        /**
         * Finds the Activity behind a Compose [Context], which is usually a
         * ContextWrapper rather than the Activity itself.
         */
        fun Context.findActivity(): Activity? {
            var ctx: Context? = this
            while (ctx is ContextWrapper) {
                if (ctx is Activity) return ctx
                ctx = ctx.baseContext
            }
            return null
        }
    }
}
