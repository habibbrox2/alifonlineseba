<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\DeviceRepository;
use App\Service\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * POST  /api/devices       register/refresh an FCM token (idempotent upsert)
 * DELETE /api/devices/{id} deactivate one device
 * GET   /api/devices       the caller's own devices (tokens never returned)
 */
final readonly class DeviceApiAction
{
    public function __construct(private DeviceRepository $devices) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $method = $request->getMethod();

        if ($method === 'POST') {
            $body = (array) $request->getParsedBody();
            $token = trim((string) ($body['token'] ?? ''));
            if ($token === '' || strlen($token) > 512) {
                return Api::fail('Valid device token required.', [], 422);
            }

            $id = $this->devices->upsert(
                $identity->id,
                $token,
                (string) ($body['platform'] ?? 'android'),
                isset($body['device_name']) ? trim((string) $body['device_name']) : null,
                isset($body['app_version']) ? mb_substr(trim((string) $body['app_version']), 0, 32) : null,
            );

            return Api::ok(['id' => $id], 'Device registered.');
        }

        $id = $route->getArgument('id');
        if ($id !== null && $method === 'DELETE') {
            $ok = $this->devices->deactivate((int) $id, $identity->id);
            return Api::ok(['deactivated' => $ok], $ok ? 'Device deactivated.' : 'Device not found.');
        }

        return Api::ok(['devices' => $this->devices->forUser($identity->id)]);
    }
}
