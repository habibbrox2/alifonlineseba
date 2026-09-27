<?php

declare(strict_types=1);

namespace App\Web\Auth;

use App\Auth\IdentityRepository;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\UrlGeneratorInterface;

final readonly class LogoutAction
{
    public function __construct(
        private IdentityRepository $identities,
        private UrlGeneratorInterface $url,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $this->identities->logout();
        return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('home')]);
    }
}
