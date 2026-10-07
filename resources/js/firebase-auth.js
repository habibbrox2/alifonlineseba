/**
 * Firebase Email/Password and Phone auth flows.
 *
 * Loaded as a module on the login and register pages when the Firebase web
 * client is configured.
 *
 * ## Flows
 *
 * ### Email/Password
 *  1. User enters email + password and clicks "Sign in with Firebase".
 *  2. `signInWithEmailAndPassword` authenticates against Firebase.
 *  3. The ID token is POSTed to `/auth/firebase` as `credential`.
 *  4. The server verifies it and logs the user in / redirects to the dashboard.
 *
 * ### Phone
 *  1. User enters phone number and clicks "Send OTP".
 *  2. `signInWithPhoneNumber` sends an SMS via Firebase, using reCAPTCHA.
 *  3. User enters the OTP and clicks "Verify".
 *  4. The resulting ID token is POSTed to `/auth/firebase` as `credential`.
 *  5. The server verifies it and logs the user in / redirects to the dashboard.
 *
 * ## Why compat and not modular
 *
 * Same reason as google-signin.js: no bundler in this project. The compat
 * SDK is a single global that works from a module without a build step.
 */
export function initFirebaseAuth(): void
{
    if (typeof firebase === 'undefined') {
        return;
    }

    initEmailPassword();
    initPhoneAuth();
}

// ---- Email / Password ------------------------------------------------------

function initEmailPassword(): void
{
    const form = document.getElementById('firebase-email-form');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const btn = form.querySelector('button[type="submit"]');
        if (!(btn instanceof HTMLButtonElement)) {
            return;
        }

        btn.disabled = true;
        btn.dataset.loading = '1';

        try {
            const email = (form.querySelector('#firebase-email') as HTMLInputElement)?.value ?? '';
            const password = (form.querySelector('#firebase-password') as HTMLInputElement)?.value ?? '';

            if (email === '' || password === '') {
                throw new Error('ইমেইল ও পাসওয়ার্ড দিন।');
            }

            const auth = firebase.auth();
            const result = await auth.signInWithEmailAndPassword(email, password);
            const credential = result.user?.getIdToken();

            if (credential === null || credential === undefined) {
                throw new Error('No ID token from Firebase.');
            }

            await submitCredential(credential);
        } catch (error) {
            handleAuthError(error, btn);
        } finally {
            btn.disabled = false;
            delete btn.dataset.loading;
        }
    });
}

// ---- Phone -----------------------------------------------------------------

function initPhoneAuth(): void
{
    const form = document.getElementById('firebase-phone-form');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const sendBtn = form.querySelector('#firebase-send-otp-btn');
    const verifyBtn = form.querySelector('#firebase-verify-otp-btn');
    const phoneInput = form.querySelector('#firebase-phone') as HTMLInputElement | null;
    const otpInput = form.querySelector('#firebase-otp') as HTMLInputElement | null;

    let confirmationResult: firebase.auth.ConfirmationResult | null = null;

    if (sendBtn instanceof HTMLButtonElement) {
        sendBtn.addEventListener('click', async () => {
            sendBtn.disabled = true;
            sendBtn.dataset.loading = '1';

            try {
                const phone = phoneInput?.value ?? '';
                if (phone === '') {
                    throw new Error('মোবাইল নম্বর দিন।');
                }

                const auth = firebase.auth();
                const recaptchaContainer = document.getElementById('firebase-recaptcha-container');
                const appVerifier = new firebase.auth.RecaptchaVerifier(recaptchaContainer || 'firebase-recaptcha', {
                    size: 'invisible',
                });

                confirmationResult = await auth.signInWithPhoneNumber(phone, appVerifier);
                showPhoneError('OTP পাঠানো হয়েছে।');

                // Show OTP input.
                if (otpInput) {
                    otpInput.closest('div')?.classList.remove('hidden');
                }
                if (verifyBtn) {
                    verifyBtn.closest('div')?.classList.remove('hidden');
                }
            } catch (error) {
                handleAuthError(error, sendBtn);
            } finally {
                sendBtn.disabled = false;
                delete sendBtn.dataset.loading;
            }
        });
    }

    if (verifyBtn instanceof HTMLButtonElement) {
        verifyBtn.addEventListener('click', async () => {
            verifyBtn.disabled = true;
            verifyBtn.dataset.loading = '1';

            try {
                if (confirmationResult === null) {
                    throw new Error('প্রথমে OTP পাঠান।');
                }

                const otp = otpInput?.value ?? '';
                if (otp === '') {
                    throw new Error('OTP দিন।');
                }

                const result = await confirmationResult.confirm(otp);
                const credential = result.user?.getIdToken();

                if (credential === null || credential === undefined) {
                    throw new Error('No ID token from Firebase.');
                }

                await submitCredential(credential);
            } catch (error) {
                handleAuthError(error, verifyBtn);
            } finally {
                verifyBtn.disabled = false;
                delete verifyBtn.dataset.loading;
            }
        });
    }
}

// ---- Shared helpers --------------------------------------------------------

async function submitCredential(credential: string): Promise<void>
{
    const response = await fetch('/auth/firebase', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': getCsrfToken(),
        },
        body: new URLSearchParams({ credential: credential }),
        credentials: 'same-origin',
    });

    if (!response.ok) {
        let message = 'ফায়ারবেজ সাইন ইন করা যায়নি।';
        try {
            const body = await response.json();
            if (body?.message) {
                message = body.message;
            }
        } catch (_) {
            // Not JSON — keep the generic message.
        }
        throw new Error(message);
    }

    const url = new URL('/dashboard', window.location.origin);
    window.location.href = url.href;
}

function handleAuthError(error: unknown, btn: HTMLButtonElement): void
{
    if (error instanceof Error) {
        let message = error.message;
        if (message === 'Popup closed by user.' || message === 'Sign-in canceled.') {
            message = 'সাইন ইন বাতিল করা হয়েছে।';
        } else if (message.includes('No ID token')) {
            message = 'ফায়ারবেজ সাইন ইন শেষে শংসাপত্র পাওয়া যায়নি।';
        } else if (message.includes('auth/') || message.includes(' Firebase ')) {
            // Keep Firebase's own message for debugging; trim auth/ prefix.
            message = message.replace(/^auth\/[a-z-]+:\s*/, '');
        }
        showError(btn, message);
    } else {
        showError(btn, 'ফায়ারবেজ সাইন ইন করা যায়নি। আবার চেষ্টা করুন।');
    }
}

function showError(btn: HTMLButtonElement, message: string): void
{
    let panel = document.getElementById('firebase-auth-error');
    if (!(panel instanceof HTMLDivElement)) {
        panel = document.createElement('div');
        panel.id = 'firebase-auth-error';
        panel.className = 'mt-3 text-sm text-danger-700 text-center transition-opacity';
        btn.after(panel);
    }

    panel.textContent = message;
    panel.style.opacity = '1';

    clearTimeout(panel._clear);
    panel._clear = setTimeout(() => {
        panel.style.opacity = '0';
        setTimeout(() => {
            if (panel.parentNode) {
                panel.parentNode.removeChild(panel);
            }
        }, 250);
    }, 4000);
}

function showPhoneError(message: string): void
{
    let panel = document.getElementById('firebase-phone-error');
    if (!(panel instanceof HTMLDivElement)) {
        panel = document.createElement('div');
        panel.id = 'firebase-phone-error';
        panel.className = 'mt-3 text-sm text-danger-700 text-center transition-opacity';
    }

    const container = document.getElementById('firebase-phone-messages');
    if (container instanceof HTMLDivElement && !panel.parentNode) {
        container.appendChild(panel);
    }

    panel.textContent = message;
    panel.style.opacity = '1';

    clearTimeout(panel._clear);
    panel._clear = setTimeout(() => {
        panel.style.opacity = '0';
        setTimeout(() => {
            if (panel.parentNode) {
                panel.parentNode.removeChild(panel);
            }
        }, 250);
    }, 4000);
}

/**
 * Read the CSRF token the same way the rest of the page's forms do.
 */
function getCsrfToken(): string
{
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta instanceof HTMLMetaElement && meta.content) {
        return meta.content;
    }

    const input = document.querySelector('input[name="_csrf"]');
    if (input instanceof HTMLInputElement && input.value) {
        return input.value;
    }

    return '';
}
