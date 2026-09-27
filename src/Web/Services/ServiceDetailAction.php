<?php

declare(strict_types=1);

namespace App\Web\Services;

use App\Auth\Identity;
use App\Repository\ServiceRepository;
use App\Repository\TransactionRepository;
use App\Service\ServiceManager;
use App\ServiceProvider\ServiceResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class ServiceDetailAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
        private ServiceManager $manager,
        private TransactionRepository $transactions,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $slug = (string) $route->getArgument('slug', '');
        $service = $this->services->findServiceBySlug($slug);

        if ($service === null || $service['status'] !== 'active') {
            return new \Nyholm\Psr7\Response(302, ['Location' => '/services']);
        }

        $category = $this->services->findCategoryById((int) $service['category_id']);
        $fields = $this->manager->fieldsFor($service);
        $provider = $this->manager->providerFor($service);
        $history = $this->transactions->forUser($identity->id, 1, 5);

        $result = null;
        $errors = [];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            unset($input['csrf']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
            $result = $this->manager->execute($service, $identity, $input, $ip, $ua);
            if (!$result->success) {
                $errors = $result->errors;
            }
        }

        return $this->view->render('site/services/detail.twig', [
            'service' => $service,
            'category' => $category,
            'fields' => $fields,
            'requirements' => $provider?->requirements() ?? [],
            'history' => $history['rows'],
            'result' => $result,
            'errors' => $errors,
            'identity' => $identity,
        ]);
    }
}
