/**
 * Social sign-in via Firebase Authentication.
 *
 * Loaded as a module on the login and register pages when the Firebase web
 * client is configured.
 *
 * ## Flow
 *
 * 1. The page loads the Firebase compat SDK from the CDN (see the partial
 *    `partials/google-signin-head.twig` for the inline config).
 * 2. On click, `signInWithPopup` gets an ID token from the provider.
 * 3. The token is POSTed to `/auth/firebase` as `credential`.
 * 4. The server verifies it and logs the user in / redirects to the dashboard.
 *
 * ## Why compat and not modular
 *
 * The modular SDK needs a bundler to resolve the `@firebase/` imports. This
 * project ships plain ES modules copied from `resources/js/` with no build
 * step for them, and adding webpack/rollup for one button is not worth it. The
 * compat SDK is a single global (`firebase`) that works from a `<script>` tag
 * and a module alike, and is what the rest of the Firebase web docs still show
 * for quick integrations.
 */
export function initGoogleSignIn(): void
{
    if (typeof firebase === 'undefined') {
        return;
    }

    const btn = document.getElementById('google-signin-btn');
    if (!(btn instanceof HTMLButtonElement)) {
        return;
    }

    btn.addEventListener('click', () => handleProviderSignIn('google'));
}

export function initFacebookSignIn(): void
{
    if (typeof firebase === 'undefined') {
        return;
    }

    const btn = document.getElementById('facebook-signin-btn');
    if (!(btn instanceof HTMLButtonElement)) {
        return;
    }

    btn.addEventListener('click', () => handleProviderSignIn('facebook'));
}

async function handleProviderSignIn(providerName: 'google' | 'facebook'): Promise<void>
{
    let btn: HTMLButtonElement | null = null;
    if (providerName === 'google') {
        btn = document.getElementById('google-signin-btn');
    } else if (providerName === 'facebook') {
        btn = document.getElementById('facebook-signin-btn');
    }
    if (!(btn instanceof HTMLButtonElement)) {
        return;
    }

    btn.disabled = true;
    btn.dataset.loading = '1';

    try {
        const auth = firebase.auth();
        let provider: firebase.auth.AuthProvider;

        if (providerName === 'google') {
            provider = new firebase.auth.GoogleAuthProvider();
            (provider as firebase.auth.GoogleAuthProvider).setCustomParameters({ prompt: 'select_account' });
        } else {
            provider = new firebase.auth.FacebookAuthProvider();
        }

        const result = await auth.signInWithPopup(provider);
        const credential = result.user?.getIdToken();
        if (credential === null || credential === undefined) {
            throw new Error('No ID token from provider.');
        }

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
            let message = 'সাইন ইন করা যায়নি।';
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
    } catch (error) {
        if (error instanceof Error) {
            let message = error.message;
            if (message === 'Popup closed by user.' || message === 'Sign-in canceled.') {
                message = 'সাইন ইন বাতিল করা হয়েছে।';
            } else if (message.includes('No ID token')) {
                message = 'সাইন ইন শেষে শংসাপত্র পাওয়া যায়নি।';
            } else if (message.includes('auth/') || message.includes(' Firebase ')) {
                message = message.replace(/^auth\/[a-z-]+:\s*/, '');
            }
            showError(btn, message);
        } else {
            showError(btn, 'সাইন ইন করা যায়নি। আবার চেষ্টা করুন।');
        }
    } finally {
        btn.disabled = false;
        delete btn.dataset.loading;
    }
}

/**
 * Read the CSRF token the same way the rest of the page's forms do.
 *
 * The auth layout renders the token in a meta tag so Alpine/Alpine-adjacent
 * scripts can read it without depending on any particular form being present.
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

/**
 * Show a transient error on the button without cluttering the page.
 *
 * The server-side flash is the primary error channel; this is only for
 * client-side failures (popup cancelled, network error) where no flash was set.
 */
function showError(btn: HTMLButtonElement, message: string): void
{
    // Brief inline message under the button, then clear it so a retry is clean.
    let panel = document.getElementById('google-signin-error');
    if (!(panel instanceof HTMLDivElement)) {
        panel = document.createElement('div');
        panel.id = 'google-signin-error';
        panel.className =
            'mt-3 text-sm text-danger-700 text-center transition-opacity';
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
