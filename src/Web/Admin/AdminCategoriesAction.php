<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\ServiceRepository;
use App\Service\CategoryAccent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class AdminCategoriesAction
{
    public function __construct(
        private WebViewRenderer $view,
        private ServiceRepository $services,
        private ActivityLogRepository $logs,
        private SessionInterface $session,
        private UrlGeneratorInterface $url,
        private CategoryAccent $accents,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $identity = $request->getAttribute('identity');
            $adminId = $identity !== null ? (int) $identity->id : null;
            $input = (array) $request->getParsedBody();
            $do = (string) ($input['do'] ?? 'create');

            if ($do === 'create') {
                $name = trim((string) ($input['name'] ?? ''));
                $slug = $this->slugify((string) ($input['slug'] ?? '') ?: $name);
                if ($name !== '' && $slug !== '') {
                    $this->services->createCategory([
                        'name' => $name,
                        'slug' => $slug,
                        'icon' => (string) ($input['icon'] ?? 'grid'),
                        'description' => (string) ($input['description'] ?? ''),
                        'accent' => $this->requestedAccent($input),
                        'sort_order' => (int) ($input['sort_order'] ?? 0),
                    ]);
                    $this->logs->create([
                        'user_id' => $adminId,
                        'action' => 'admin.category.created',
                        'description' => "Category '{$name}' created",
                        'metadata' => ['slug' => $slug],
                    ]);
                    $this->session->set('flash_success', 'ক্যাটাগরি তৈরি হয়েছে।');
                }
            } elseif ($do === 'update') {
                $id = (int) ($input['id'] ?? 0);
                $this->services->updateCategory($id, [
                    'name' => trim((string) ($input['name'] ?? '')),
                    'icon' => (string) ($input['icon'] ?? 'grid'),
                    'description' => (string) ($input['description'] ?? ''),
                    'accent' => $this->requestedAccent($input),
                    'sort_order' => (int) ($input['sort_order'] ?? 0),
                    'status' => ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                ]);
                $this->session->set('flash_success', 'ক্যাটাগরি আপডেট হয়েছে।');
            } elseif ($do === 'toggle') {
                $id = (int) ($input['id'] ?? 0);
                foreach ($this->services->allCategories(false) as $c) {
                    if ((int) $c['id'] === $id) {
                        $this->services->updateCategory($id, [
                            'status' => $c['status'] === 'active' ? 'inactive' : 'active',
                        ]);
                        break;
                    }
                }
                $this->session->set('flash_success', 'ক্যাটাগরি স্ট্যাটাস পরিবর্তন হয়েছে।');
            } elseif ($do === 'delete') {
                $id = (int) ($input['id'] ?? 0);
                $categories = $this->services->allCategories(false);
                $category = null;
                foreach ($categories as $c) {
                    if ((int) $c['id'] === $id) {
                        $category = $c;
                        break;
                    }
                }
                $this->services->deleteCategory($id);
                if ($category !== null) {
                    $this->logs->create([
                        'user_id' => $adminId,
                        'action' => 'admin.category.deleted',
                        'description' => "Category '{$category['name']}' deleted",
                        'metadata' => ['category_id' => $id],
                    ]);
                }
                $this->session->set('flash_success', 'ক্যাটাগরি মুছে ফেলা হয়েছে।');
            }

            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('admin-categories')]);
        }

        $editId = (int) ($request->getQueryParams()['edit'] ?? 0);
        $editCategory = null;
        if ($editId > 0) {
            foreach ($this->services->allCategories(false) as $c) {
                if ((int) $c['id'] === $editId) {
                    $editCategory = $c;
                    break;
                }
            }
        }

        return $this->view->render('site/admin/categories.twig', [
            'categories' => $this->services->allCategories(false),
            'editCategory' => $editCategory,
        ]);
    }

    /**
     * `auto` (and anything unknown) means "let the resolver decide from the
     * name/slug", which is why it is stored rather than rejected.
     *
     * @param array<string, mixed> $input
     */
    private function requestedAccent(array $input): string
    {
        $accent = (string) ($input['accent'] ?? 'auto');
        return $accent === 'auto' || !$this->accents->isValid($accent) ? 'auto' : $accent;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-') ?: 'category-' . random_int(100, 999);
    }
}
