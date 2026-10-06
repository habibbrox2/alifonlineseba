<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Service\ImageUploadStorage;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;

/**
 * Exposes the image upload's `accept` list and size ceiling to every template.
 *
 * ## Why the limits come from the class rather than the markup
 *
 * `components/form-row.twig` renders a drop zone, and a drop zone that lies
 * about what it takes is worse than no drop zone: the browser's own file
 * chooser filter, the `accept` attribute and the server's allowlist have to be
 * the same three rules, or the user is told "that is fine" by one of them and
 * then refused by another. The allowlist and the ceiling already live on
 * {@see ImageUploadStorage} because that is the only place they are enforced,
 * so the values the widget advertises are read from there rather than copied
 * into the template where they would drift on the next change.
 *
 * The client-side check is a convenience, never the control. It saves a round
 * trip and a confusing error message; `ImageUploadStorage::store()` is what
 * actually decides, and it sniffs the bytes rather than believing the browser.
 */
final class ImageUploadViewInjection implements CommonParametersInjectionInterface
{
    public function getCommonParameters(): array
    {
        $storage = ImageUploadStorage::fromProjectRoot();

        return [
            'imageUploadAccept' => ImageUploadStorage::ACCEPT_ATTRIBUTE,
            'imageUploadMaxBytes' => ImageUploadStorage::MAX_BYTES,
            'imageUploadMaxLabel' => $storage->humanSize(ImageUploadStorage::MAX_BYTES),
        ];
    }
}