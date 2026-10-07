<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Env;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;

/**
 * Exposes the Firebase/Google Sign-In configuration to every template.
 *
 * The client-side JS (resources/js/google-signin.js) needs the Firebase web
 * app config to initialise the SDK and call `signInWithPopup`. That config is
 * environment-specific (different projects, different client ids), so it comes
 * from the environment rather than being hardcoded.
 *
 * When the client id is not set the button is hidden entirely (the partial
 * `partials/google-signin-btn.twig` guards on `googleSigninClientId is not
 * empty`), and this injection still runs — it just produces empty values that
 * the head partial skips. This keeps the template logic in one place: one
 * check, `googleSigninClientId is not empty`, gates both the button and the
 * SDK load.
 */
final class GoogleSignInViewInjection implements CommonParametersInjectionInterface
{
    public function getCommonParameters(): array
    {
        return [
            'googleSigninClientId' => Env::get('GOOGLE_SIGNIN_WEB_CLIENT_ID'),
            'googleSigninApiKey' => Env::get('GOOGLE_SIGNIN_API_KEY'),
            'googleSigninAuthDomain' => Env::get('GOOGLE_SIGNIN_AUTH_DOMAIN'),
            'googleSigninProjectId' => Env::get('GOOGLE_SIGNIN_PROJECT_ID'),
            'googleSigninAppId' => Env::get('GOOGLE_SIGNIN_APP_ID'),
        ];
    }
}
