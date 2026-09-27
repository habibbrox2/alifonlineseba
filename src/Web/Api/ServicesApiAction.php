<?php

declare(strict_types=1);

namespace App\Web\Api;

use App\Auth\Identity;
use App\Repository\ServiceRepository;
use App\Service\Api;
use App\Service\ServiceManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class ServicesApiAction
{
    public function __construct(
        private ServiceRepository $services,
        private ServiceManager $manager,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $slug = $route->getArgument('slug');
        if ($slug !== null) {
            $service = $this->services->findServiceBySlug($slug);
            if ($service === null) {
                return Api::fail('Service not found.', [], 404);
            }
            return Api::ok([
                'service' => $service,
                'fields' => array_map(static fn ($f) => $f->toArray(), $this->manager->fieldsFor($service)),
            ]);
        }

        $params = $request->getQueryParams();
        $categoryId = isset($params['category']) ? (int) $params['category'] : null;
        $services = $categoryId !== null
            ? $this->services->servicesByCategory($categoryId)
            : $this->services->servicesByCategory();

        return Api::ok(['services' => $services]);
    }
}
