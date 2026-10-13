/**
 * Social sign-in via Firebase Authentication.
 *
 * Loaded as a module on the login and register pages when the Firebase web
 * client is configured.
 *
 * ## Flow
 *
 * 1. The page loads the Firebase compat SDK from the CDN (see the partial
 *    `partials/firebase-auth-head.twig` for the inline config).
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
 *
 * Plain JavaScript only — these files are served without a build step, so any
 * TypeScript annotation here would be a browser SyntaxError that silently
 * disables the buttons.
 */
export function initFirebaseSocialSignIn()
{
    if (typeof firebase === 'undefined') {
        return;
    }

    const btn = document.getElementById('firebase-signin-btn');
    if (!(btn instanceof HTMLButtonElement)) {
        return;
    }

    btn.addEventListener('click', () => handleProviderSignIn(btn, 'google'));
}

export function initFacebookSignIn()
{
    if (typeof firebase === 'undefined') {
        return;
    }

    const btn = document.getElementById('facebook-signin-btn');
    if (!(btn instanceof HTMLButtonElement)) {
        return;
    }

    btn.addEventListener('click', () => handleProviderSignIn(btn, 'facebook'));
}

async function handleProviderSignIn(btn, providerName)
{
    btn.disabled = true;
    btn.dataset.loading = '1';

    try {
        const auth = firebase.auth();
        let provider;

        if (providerName === 'google') {
            provider = new firebase.auth.GoogleAuthProvider();
            provider.setCustomParameters({ prompt: 'select_account' });
        } else {
            provider = new firebase.auth.FacebookAuthProvider();
        }

        const result = await auth.signInWithPopup(provider);
        // getIdToken() is a promise; awaiting it gives the actual token. A
        // raw Promise posted as a form field stringifies to "[object Promise]".
        const credential = result.user ? await result.user.getIdToken() : null;
        if (!credential) {
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
        let message = 'সাইন ইন করা যায়নি। আবার চেষ্টা করুন।';
        if (error instanceof Error && error.message) {
            const raw = error.message;
            if (raw === 'Popup closed by user.' || raw === 'Sign-in canceled.' || raw.includes('popup-closed') || raw.includes('/auth/popup')) {
                message = 'সাইন ইন বাতিল করা হয়েছে।';
            } else if (raw.includes('No ID token')) {
                message = 'সাইন ইন শেষে শংসাপত্র পাওয়া যায়নি।';
            } else {
                // Keep Firebase's own copy (already human-readable); only the
                // machine prefix `auth/foo: ` is stripped.
                message = raw.replace(/^auth\/[a-z-]+:\s*/, '');
            }
        }
        showError(btn, message);
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
function getCsrfToken()
{
    // Layouts/base.twig renders the token as `<meta name="_csrf">` (and the
    // older name "csrf" in legacy pages); the auth pages never use
    // "csrf-token", so fall through until a name actually matches.
    const meta = document.querySelector('meta[name="_csrf"]') || document.querySelector('meta[name="csrf"]');
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
function showError(btn, message)
{
    // Brief inline message under the button, then clear it so a retry is clean.
    let panel = document.getElementById('firebase-signin-error');
    if (!(panel instanceof HTMLDivElement)) {
        panel = document.createElement('div');
        panel.id = 'firebase-signin-error';
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
