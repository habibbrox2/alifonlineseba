<?php

declare(strict_types=1);

namespace App\Web\Services;

use App\Auth\Identity;
use App\Repository\ServiceRepository;
use App\Repository\ServiceOrderRepository;
use App\Service\OrderWindowService;
use App\Service\ServiceManager;
use App\ServiceProvider\ServiceResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class ServiceDetailAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
        private ServiceManager $manager,
        private OrderWindowService $window,
        private ServiceOrderRepository $orders,
        private SessionInterface $session,
        private UrlGeneratorInterface $url,
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
        $history = $this->orders->forUser($identity->id, 1, 5);
        $serviceHistory = $this->orders->forUserByService($identity->id, (int) $service['id']);
        // The variant label lives in the transaction metadata JSON; surface it
        // as a plain column for the per-service history table.
        foreach ($serviceHistory['rows'] as $i => $row) {
            $serviceHistory['rows'][$i]['variant_label'] = \App\Repository\ServiceOrderRepository::metadata($row)['variant'] ?? null;
        }
        $variants = \App\Service\ServiceManager::variantsFor($service);
        $rules = \App\Service\ServiceManager::rulesFor($service);

        $result = null;
        $errors = [];

        // Prefill from the query string — the dashboard's "recent searches"
        // panel links here as `/services/view/<slug>?<field>=<value>` so a
        // re-run is one click instead of retyping an NID. Only names the
        // service actually declares are accepted, so a crafted link cannot
        // stuff arbitrary values into the form.
        $prefill = $this->prefill($request, $fields);

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();
            unset($input['csrf']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
            $result = $this->manager->submit($service, $identity, $input, $ip, $ua);
            if (!$result->success) {
                $errors = $result->errors;
            } else {
                // The request is queued, not executed — the history page owns the
                // lifecycle, so send the user straight there.
                $this->session->set('flash_success', $result->message);
                return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('service-history')]);
            }
        }

        return $this->view->render('site/services/detail.twig', [
            'service' => $service,
            'category' => $category,
            'fields' => $fields,
            'requirements' => $provider?->requirements() ?? [],
            'history' => $history['rows'],
            'serviceHistory' => $serviceHistory['rows'],
            'serviceHistoryTotal' => $serviceHistory['total'],
            'variants' => $variants,
            'rules' => $rules,
            'result' => $result,
            'orderWindow' => $this->window,
            'errors' => $errors,
            'prefill' => $prefill,
            'identity' => $identity,
        ]);
    }

    /**
     * Map query parameters onto the service's configured form fields.
     *
     * Scalars only, capped in length: the value ends up back in an HTML
     * attribute, and a 2 KB query string is not a legitimate NID.
     *
     * @param array<int, \App\ServiceProvider\ServiceField> $fields
     * @return array<string, string>
     */
    private function prefill(ServerRequestInterface $request, array $fields): array
    {
        if ($request->getMethod() === 'POST') {
            return [];
        }

        $query = $request->getQueryParams();
        $prefill = [];

        foreach ($fields as $field) {
            $raw = $query[$field->name] ?? null;
            if (!is_scalar($raw)) {
                continue;
            }
            $value = trim((string) $raw);
            if ($value === '' || mb_strlen($value) > 128) {
                continue;
            }
            $prefill[$field->name] = $value;
        }

        return $prefill;
    }
}
