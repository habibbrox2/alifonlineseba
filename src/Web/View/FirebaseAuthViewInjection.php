<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Env;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;

/**
 * Exposes Firebase Authentication configuration to every template.
 *
 * The Firebase web SDK needs the Firebase web
 * app config to initialise the SDK and call `signInWithPopup`. That config is
 * environment-specific (different projects, different client ids), so it comes
 * from the environment rather than being hardcoded.
 *
 * When the web client id is not set the button is hidden entirely (the partial
 * `partials/firebase-auth-btn.twig` guards on `firebaseAuthWebClientId is not
 * empty`), and this injection still runs — it just produces empty values that
 * the head partial skips. This keeps the template logic in one place: one
 * check, `firebaseAuthWebClientId is not empty`, gates both the button and the
 * SDK load.
 */
final class FirebaseAuthViewInjection implements CommonParametersInjectionInterface
{
    public function getCommonParameters(): array
    {
        return [
            'firebaseAuthWebClientId' => Env::get('FIREBASE_AUTH_WEB_CLIENT_ID'),
            'firebaseAuthApiKey' => Env::get('FIREBASE_API_KEY'),
            'firebaseAuthDomain' => Env::get('FIREBASE_AUTH_DOMAIN'),
            'firebaseAuthProjectId' => Env::get('FIREBASE_PROJECT_ID'),
            'firebaseAuthAppId' => Env::get('FIREBASE_APP_ID'),
        ];
    }
}
