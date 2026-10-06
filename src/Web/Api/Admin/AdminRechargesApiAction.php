<?php

declare(strict_types=1);

namespace App\Web\Api\Admin;

use App\Auth\Identity;
use App\Repository\TopupRepository;
use App\Service\Api;
use App\Service\TopupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class AdminRechargesApiAction
{
    public function __construct(
        private TopupRepository $topups,
        private TopupService $topupService,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        if (!$identity->canAccessAdmin()) {
            return Api::forbidden();
        }

        $id = (int) $route->getArgument('id', '0');
        $row = $this->topups->findById($id);
        if ($row === null) {
            return Api::fail('রিচার্জ রিকোয়েস্ট পাওয়া যায়নি।', [], 404);
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost((array) $request->getParsedBody(), $row, $identity);
        }

        return Api::ok(['topup' => $row]);
    }

    private function handlePost(array $input, array $row, Identity $identity): ResponseInterface
    {
        $action = (string) ($input['do'] ?? '');

        return match ($action) {
            'approve' => $this->approve($row, $identity),
            'reject' => $this->reject($row, $input, $identity),
            default => Api::fail('অজানা অ্যাকশন।'),
        };
    }

    private function approve(array $row, Identity $identity): ResponseInterface
    {
        [$ok] = $this->topupService->approve($row['id'], (int) $identity->id);

        return $ok
            ? Api::ok(['message' => 'রিচার্জ অনুমোদিত হয়েছে।'])
            : Api::fail('রিচার্জ অনুমোদন করা যায়নি।');
    }

    private function reject(array $row, array $input, Identity $identity): ResponseInterface
    {
        $note = trim((string) ($input['note'] ?? ''));
        [$ok] = $this->topupService->reject($row['id'], (int) $identity->id, $note);

        return $ok
            ? Api::ok(['message' => 'রিচার্জ বাতিল করা হয়েছে।'])
            : Api::fail('রিচার্জ বাতিল করা যায়নি।');
    }
}
