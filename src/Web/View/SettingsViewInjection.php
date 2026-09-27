<?php

declare(strict_types=1);

namespace App\Web\View;

use App\Repository\SettingsRepository;
use Yiisoft\Yii\View\Renderer\CommonParametersInjectionInterface;

/**
 * Exposes admin-managed site settings (tagline, contacts, social links)
 * as a `site` variable in every Twig template.
 */
final class SettingsViewInjection implements CommonParametersInjectionInterface
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function getCommonParameters(): array
    {
        return [
            'site' => $this->settings->all(),
        ];
    }
}
