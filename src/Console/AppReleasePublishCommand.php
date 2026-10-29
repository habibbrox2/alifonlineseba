<?php

declare(strict_types=1);

namespace App\Console;

use App\Repository\AppReleaseRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publishes an APK into the self-hosted release channel.
 *
 *   php yii app:release:publish --file=path/to/app.apk --channel=admin
 *
 * Defaults `--version-code` and `--version-name` from the admin Android
 * build.gradle.kts, so a normal run from the developer's checkout only needs
 * the file and the channel.
 */
#[AsCommand('app:release:publish', 'Copy an APK into the releases directory and publish its manifest row.')]
final class AppReleasePublishCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the APK that should be served.')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Release channel: twa or admin (default twa).', 'twa')
            ->addOption('version-code', null, InputOption::VALUE_REQUIRED, 'Monotonic Android versionCode (defaults to android-admin/app/build.gradle.kts).')
            ->addOption('version-name', null, InputOption::VALUE_REQUIRED, 'Human-facing version name (defaults to android-admin/app/build.gradle.kts).')
            ->addOption('min-version-code', null, InputOption::VALUE_REQUIRED, 'Block installs below this floor.', '0')
            ->addOption('notes', null, InputOption::VALUE_REQUIRED, 'Release notes.', '')
            ->setHelp(
                "Pick the APK an operator wants to serve, copy it into the releases directory\n"
                . "relative to the site, hash it, and promote it to `is_published = 1` in\n"
                . "the chosen channel. A second run for the same channel + version updates\n"
                . "the existing row instead of colliding with the unique index."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $input->getOption('file');
        if (!is_string($file) || $file === '') {
            $io->error('--file is required.');
            return self::FAILURE;
        }

        $channel = (string) $input->getOption('channel');
        if (!in_array($channel, ['twa', 'admin'], true)) {
            $io->error('Unknown release channel (use twa or admin).');
            return self::FAILURE;
        }

        $source = $this->resolveSource($file);
        if ($source === null || !is_file($source) || !is_readable($source)) {
            $io->error(sprintf('No readable APK at "%s".', $file));
            return self::FAILURE;
        }

        [$versionCode, $versionName] = $this->versionDefaults($input->getOption('version-code'), $input->getOption('version-name'));
        if ($versionCode === null || $versionName === null) {
            $io->error('Could not determine the version code/name. Pass --version-code and --version-name explicitly.');
            return self::FAILURE;
        }

        $root = dirname(__DIR__, 2);
        $releasesDir = $root . '/web/releases';
        if (!is_dir($releasesDir)) {
            @mkdir($releasesDir, 0o750, true);
        }

        $safeName = $this->sanitiseFilename('alif-tools-' . $versionName . '-' . $channel . '.apk');
        $dest = $releasesDir . '/' . $safeName;
        if (!$this->copySource($source, $dest)) {
            $io->error(sprintf('Could not copy "%s" to the releases directory.', $source));
            return self::FAILURE;
        }

        $size = @filesize($dest);
        $sha = @hash_file('sha256', $dest);
        if ($size === false || !is_string($sha)) {
            $io->error('The copied file cannot be read back.');
            @unlink($dest);
            return self::FAILURE;
        }

        $id = $this->releases->publish([
            'channel' => $channel,
            'version_code' => (int) $versionCode,
            'version_name' => $versionName,
            'apk_path' => $safeName,
            'apk_size' => (int) $size,
            'sha256' => $sha,
            'min_version_code' => (int) $input->getOption('min-version-code'),
            'release_notes' => (string) $input->getOption('notes'),
        ]);

        $io->success(sprintf('Published release %d on the "%s" channel.', $id, $channel));
        $io->definitionList(
            ['version_code' => (string) $versionCode],
            ['version_name' => $versionName],
            ['channel' => $channel],
            ['file' => $dest],
            ['sha256' => $sha],
        );

        return self::SUCCESS;
    }

    private function resolveSource(string $file): ?string
    {
        if (is_file($file) && is_readable($file)) {
            return $file;
        }
        $root = dirname(__DIR__, 2);
        $candidate = $root . '/' . ltrim($file, '/');
        return is_file($candidate) && is_readable($candidate) ? $candidate : null;
    }

    /**
     * @return array{0:int|null,1:string|null}
     */
    private function versionDefaults(mixed $explicitCode, mixed $explicitName): array
    {
        $code = is_string($explicitCode) && $explicitCode !== ''
            ? (int) filter_var($explicitCode, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE])
            : null;
        $name = is_string($explicitName) && $explicitName !== '' ? trim($explicitName, " \t\n\r\"'") : null;

        if ($code !== null && $name !== null) {
            return [$code, $name];
        }

        $gradle = dirname(__DIR__, 2) . '/android-admin/app/build.gradle.kts';
        $source = is_file($gradle) ? (string) file_get_contents($gradle) : '';
        if ($name === null) {
            $name = $this->parseGradle($source, 'versionName', false)
                ?: (getenv('APP_VERSION_NAME') ?: null)
                ?: (getenv('GITHUB_REF_NAME') ? ltrim(preg_replace('/^android-admin-v?/', '', getenv('GITHUB_REF_NAME')), 'v') : null)
                ?: '1.1.2';
        }
        if ($code === null) {
            $code = $this->parseGradle($source, 'versionCode', true)
                ?: (getenv('APP_VERSION_CODE') ? (int) getenv('APP_VERSION_CODE') : null);
            if ($code === null && $name !== null) {
                $digits = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $name)));
                $code = ($digits[0] ?? 1) * 10000 + ($digits[1] ?? 0) * 100 + ($digits[2] ?? 0);
            }
        }

        return [$code, $name];
    }

    private function parseGradle(string $source, string $key, bool $numeric): mixed
    {
        if ($numeric) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\s*=\s*(\d+)/', $source, $m) !== 1) {
                return null;
            }
            return (int) $m[1];
        }

        if (preg_match('/\b' . preg_quote($key, '/') . '\s*=\s*"([^"]+)"/', $source, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    private function sanitiseFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? '';
        return $name === '' ? 'alif-tools.apk' : $name;
    }

    private function copySource(string $source, string $dest): bool
    {
        // Same bytes, no path traversal: dest basename is whitelisted above.
        return @copy($source, $dest);
    }

}
