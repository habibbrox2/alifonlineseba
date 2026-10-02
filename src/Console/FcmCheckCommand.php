<?php

declare(strict_types=1);

namespace App\Console;

use App\Notification\Channel\FcmChannel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the Firebase/FCM setup end to end:
 *
 *   php yii app:fcm:check                  # config + credentials + OAuth token
 *   php yii app:fcm:check --token=...      # + one real test message to a device
 *
 * Run this after dropping the service-account JSON on the server — it proves
 * the sending path works before any user-facing event exercises the queue.
 */
#[AsCommand('app:fcm:check', 'Verify the Firebase/FCM configuration (and optionally send a test push).')]
final class FcmCheckCommand extends Command
{
    public function __construct(
        private readonly FcmChannel $fcm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'token',
            null,
            InputOption::VALUE_REQUIRED,
            'Also send one test message to this FCM device token.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectId = (string) \App\Env::get('FIREBASE_PROJECT_ID', '');
        $io->section('Configuration');
        $io->text([
            'FIREBASE_PROJECT_ID: ' . ($projectId !== '' ? $projectId : '<not set>'),
            'FIREBASE_CREDENTIALS_PATH: ' . ((string) \App\Env::get('FIREBASE_CREDENTIALS_PATH', '') ?: '<not set>'),
        ]);

        $error = $this->fcm->credentialsError();
        if ($error !== '') {
            $io->error($error);
            return Command::FAILURE;
        }
        $io->success("Credentials valid — OAuth access token minted for project '{$projectId}'.");

        $token = (string) $input->getOption('token');
        if ($token === '') {
            $io->note('No --token given: the OAuth path is proven, but no message was sent.');
            return Command::SUCCESS;
        }

        $io->section('Test message');
        $result = $this->fcm->sendToToken($token, [
            'title' => 'FCM test',
            'body' => 'app:fcm:check টেস্ট পুশ — ' . date('Y-m-d H:i:s'),
            'data' => ['event' => 'fcm_test'],
        ]);
        if ($result->ok) {
            $io->success('Test message accepted by FCM' . ($result->providerMessageId !== '' ? ": {$result->providerMessageId}" : '.'));
            return Command::SUCCESS;
        }

        $io->error('Test message failed: ' . $result->error);
        return Command::FAILURE;
    }
}
