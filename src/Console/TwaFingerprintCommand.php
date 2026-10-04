<?php

declare(strict_types=1);

namespace App\Console;

use App\Service\TwaAssetLinks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Works out the Digital Asset Links statement for the Android app:
 *
 *   php yii app:twa:fingerprints                            # what is configured right now
 *   php yii app:twa:fingerprints --cert=release.cer         # + the fingerprint of a certificate
 *   php yii app:twa:fingerprints --cert=release.cer --package=online.broxlab.aliftools
 *
 * ## Why a command exists for this at all
 *
 * The TWA association is a chicken-and-egg problem. Android will not run the
 * app full screen until `/.well-known/assetlinks.json` lists the app's signing
 * certificate — and the certificate does not exist until somebody runs a
 * build. Every guide solves it by making you copy a fingerprint out of a
 * `keytool` run and paste it into JSON by hand, with no way to tell whether
 * the result is right until the app is on a phone.
 *
 * This closes that loop. Point it at the certificate the build produced and
 * it prints the exact value to paste into `.env`, plus the document the site
 * will then serve, so the two can be compared before shipping.
 *
 * ## Why it reads the certificate itself
 *
 * Computing SHA-256 over a DER certificate is `hash_file('sha256', …)`. That
 * needs no JDK, no `keytool`, no openssl binary — so this works on the same
 * XAMPP box the site is served from, which is exactly where someone fixing a
 * failed verification will be standing.
 *
 * ## What this cannot do
 *
 * It reads a *certificate*. It cannot read a keystore, because a keystore is
 * a password-protected container and a `.p12` is not a certificate. Extract
 * one first with keytool (the command is printed on demand).
 */
#[AsCommand('app:twa:fingerprints', 'Show the Digital Asset Links statement and turn a certificate into a fingerprint.')]
final class TwaFingerprintCommand extends Command
{
    private const KEYTOOL_HINT = <<<'TXT'
        Export the certificate from the keystore first, then point this at it:

          keytool -exportcert -alias <alias> -keystore <keystore> -file release.cer

        Android Studio can also do this: Build > Generate Signed Bundle/APK,
        then use the certificate it reports.
        TXT;

    protected function configure(): void
    {
        $this
            ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'A .cer/.der (DER) or .pem certificate to fingerprint.')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'The application id to attribute the certificate to.')
            ->setHelp(
                "Prints the /.well-known/assetlinks.json this site currently serves.\n"
                . "With --cert, also turns that certificate into the value to put in TWA_FINGERPRINTS."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $links = TwaAssetLinks::fromEnv();

        $this->reportCurrent($io, $links);

        $cert = $input->getOption('cert');
        if (!is_string($cert) || $cert === '') {
            return self::SUCCESS;
        }

        return $this->reportCertificate($io, $links, $cert, $input->getOption('package'));
    }

    private function reportCurrent(SymfonyStyle $io, TwaAssetLinks $links): void
    {
        $io->title('Digital Asset Links — current configuration');

        if (!$links->isConfigured()) {
            $io->warning(
                'Not configured, so /.well-known/assetlinks.json answers 404 and the Android app'
                . ' will run in a browser bar instead of full screen.'
            );
            $io->definitionList(
                ['TWA_ORIGIN' => 'not set — must be a bare https origin, e.g. https://allseba.dgtts.org'],
                ['TWA_FINGERPRINTS' => 'not set — one or more package@SHA256:AA:BB:… entries'],
            );
            $io->text('');
            $io->text('Run this again with <info>--cert</info> to turn a certificate into the value.');
            return;
        }

        $io->success('Configured. /.well-known/assetlinks.json will serve:');

        $json = json_encode(
            $links->json(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $io->section('assetlinks.json');
        $io->text($json === false ? '{}' : $json);

        $io->section('TWA_FINGERPRINTS');
        $entries = [];
        foreach ($links->json() as $statement) {
            /** @var array{sha256_cert_fingerprints: string[]} $target */
            $target = $statement['target'];
            foreach ($target['sha256_cert_fingerprints'] as $fingerprint) {
                $entries[] = $target['package_name'] . '@' . $fingerprint;
            }
        }
        $io->text(implode(',', $entries));

        $io->note('Remember a debug build and a release build are signed by different certificates.');
    }

    private function reportCertificate(
        SymfonyStyle $io,
        TwaAssetLinks $links,
        string $path,
        mixed $packageOption,
    ): int {
        if (!is_file($path) || !is_readable($path)) {
            $io->error(sprintf('No readable certificate at "%s".', $path));
            $io->text(self::KEYTOOL_HINT);
            return self::FAILURE;
        }

        $der = $this->derBytes($path);
        if ($der === null) {
            $io->error(sprintf('"%s" is not a certificate this command can read.', basename($path)));
            $io->text('Expected a DER certificate (.cer/.der) or a PEM one ("-----BEGIN CERTIFICATE-----").');
            $io->text(self::KEYTOOL_HINT);
            return self::FAILURE;
        }

        $fingerprint = implode(':', str_split(strtoupper(hash('sha256', $der)), 2));

        $package = is_string($packageOption) && $packageOption !== ''
            ? $packageOption
            : $this->guessPackage($links);

        $io->title('Certificate fingerprint');
        $io->definitionList(
            ['File' => $path],
            ['Bytes' => (string) strlen($der)],
            ['Package' => $package],
        );
        $io->success($fingerprint);

        $io->section('Add to .env');
        $io->text(
            'TWA_ORIGIN=' . ($links->origin() !== '' ? $links->origin() : 'https://allseba.dgtts.org')
            . "\nTWA_FINGERPRINTS=" . $package . '@' . $fingerprint
        );
        $io->text('Append a comma and another package@fingerprint for your other build (debug vs release).');

        return self::SUCCESS;
    }

    /**
     * The DER bytes of a certificate, whatever container it arrived in.
     *
     * A `.cer` exported by keytool is raw DER, so hashing the file works
     * directly. A `.pem` is ASCII armour around the same bytes, so the armour
     * is stripped and the base64 inside is decoded — hashing the *text* would
     * produce a fingerprint that looks plausible and verifies nothing.
     */
    private function derBytes(string $path): ?string
    {
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        if (!str_contains($contents, '-----BEGIN')) {
            return $contents;
        }

        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $contents, $m) !== 1) {
            return null;
        }

        $decoded = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * The package to file a new certificate under.
     *
     * Reusing the package already configured is usually what is wanted — an
     * operator adding a release certificate to an existing association should
     * not have to retype it.
     *
     * There is no way to read the package out of a certificate, so when nothing
     * is configured yet the TWA's own applicationId is offered. That constant
     * has to be copied from twa/app/build.gradle.kts, and it is *not* the same
     * as the native client's `online.broxlab.aliftools` — filing a TWA
     * certificate under the native client's id publishes an association that
     * no TWA APK can ever satisfy, and the symptom is the app quietly running
     * in a browser bar. `--package` is the escape hatch when that is
     * deliberately wanted.
     */
    private function guessPackage(TwaAssetLinks $links): string
    {
        $packages = array_keys($links->json() === []
            ? []
            : array_map(
                static fn (array $s): string => (string) $s['target']['package_name'],
                $links->json(),
            )
        );

        // Mirrors applicationId in twa/app/build.gradle.kts and packageId in
        // twa/twa-manifest.json.
        return $packages[0] ?? 'online.broxlab.aliftools.twa';
    }
}
