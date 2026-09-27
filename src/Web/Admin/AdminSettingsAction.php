<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\SettingsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminSettingsAction
{
    public function __construct(
        private WebViewRenderer $view,
        private SettingsRepository $settings,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $errors = [];
        $input = $this->settings->all();

        if ($request->getMethod() === 'POST') {
            $identity = $request->getAttribute('identity');
            $input = array_map(
                static fn ($v) => trim((string) $v),
                array_intersect_key((array) $request->getParsedBody(), SettingsRepository::KEYS),
            );

            foreach ($input as $key => $value) {
                if ($value !== '' && $this->settings->isUrlKey($key) && !$this->settings->isSafeUrl($value)) {
                    $errors[$key] = 'সঠিক http(s) লিংক দিন (অথবা খালি রাখুন)।';
                }
                if ($value !== '' && ($key === 'contact_email' || $key === 'contact_phone')) {
                    $valid = $key === 'contact_email'
                        ? filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                        : preg_match('/^[0-9+\-\s()]{6,20}$/', $value) === 1;
                    if (!$valid) {
                        $errors[$key] = $key === 'contact_email' ? 'সঠিক ইমেইল দিন।' : 'সঠিক ফোন নম্বর দিন।';
                    }
                }
            }

            if ($errors === []) {
                $this->settings->putMany($input, $identity !== null ? (int) $identity->id : null);
                $this->session->set('flash_success', 'সাইট সেটিংস সংরক্ষণ হয়েছে।');

                return new \Nyholm\Psr7\Response(302, ['Location' => '/admin/settings']);
            }
        }

        return $this->view->render('site/admin/settings.twig', [
            'settings' => $input,
            'errors' => $errors,
        ]);
    }
}
