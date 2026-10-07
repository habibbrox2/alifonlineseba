<?php

declare(strict_types=1);

namespace App\Web\Account;

use App\Auth\Identity;
use App\Repository\UserRepository;
use App\Service\ImageUploadStorage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

/**
 * GET /profile/avatar/{id} — streams the authenticated user's avatar.
 *
 * This action is the *only* way an avatar stored by {@see ImageUploadStorage}
 * ever leaves the server. Because the image upload storage keeps files outside
 * the document root there is no static URL to guess, so authorisation is
 * enforced here: the owning user may read their own avatar, and admins/staff
 * may read any — the same ownership model as {@see DeliverableAction} and
 * {@see ReceiptAction}.
 *
 * `nosniff` is mandatory: an avatar is an image, and without it a browser may
 * reinterpret a GIF as HTML and execute it. The `Cache-Control: private, no-store`
 * header keeps the avatar out of shared caches (it is per-user), and `Content-Disposition:
 * inline` lets the browser render it in place (profile page, navbar) rather than
 * forcing a download.
 */
final readonly class AvatarAction
{
    public function __construct(
        private UserRepository $users,
        private ImageUploadStorage $storage,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');
        $id = (int) $route->getArgument('id', '0');

        // Same response for "not yours" and "does not exist" so the endpoint
        // cannot be used to probe which user ids have avatars.
        $row = $this->users->findById($id);
        if ($row === null) {
            return $this->textResponse(404, 'প্রোফাইল ছবি পাওয়া যায়নি।');
        }

        $isOwner = (int) $row['id'] === $identity->id;
        if (!$isOwner && !$identity->canAccessAdmin()) {
            return $this->textResponse(404, 'প্রোফাইল ছবি পাওয়া যায়নি।');
        }

        $relativePath = $row['avatar'] ?? null;
        $absolute = $this->storage->absolutePath($relativePath);

        if ($absolute === null || !is_file($absolute)) {
            return $this->textResponse(404, 'প্রোফাইল ছবি পাওয়া যায়নি।');
        }

        $mime = ImageUploadStorage::MIME_EXTENSIONS[$this->sniffMime($absolute)] ?? 'image/png';

        try {
            $stream = $this->streamFactory->createStreamFromFile($absolute);
            $size = filesize($absolute);
        } catch (\Throwable) {
            return $this->textResponse(404, 'প্রোফাইল ছবি পাঠানো যায়নি।');
        }

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) ($size === false ? 0 : $size))
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    private function sniffMime(string $absolute): string
    {
        $dimensions = @getimagesize($absolute);
        if (is_array($dimensions) && isset($dimensions[2])) {
            return match ($dimensions[2]) {
                IMAGETYPE_JPEG => 'image/jpeg',
                IMAGETYPE_PNG => 'image/png',
                IMAGETYPE_WEBP => 'image/webp',
                IMAGETYPE_GIF => 'image/gif',
                default => 'application/octet-stream',
            };
        }

        return 'application/octet-stream';
    }

    private function textResponse(int $status, string $message): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($message));
    }
}
