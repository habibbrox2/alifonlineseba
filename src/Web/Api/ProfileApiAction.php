<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ProfileApiAction
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        return Api::ok([
            'id' => $identity->id,
            'username' => $identity->username,
            'phone' => $identity->phone,
            'email' => $identity->email,
            'role' => $identity->role,
            'balance' => $identity->balance,
            'avatar' => $identity->avatar,
        ]);
    }
}
