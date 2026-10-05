<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Notification\MessageTemplates;
use App\Notification\NotificationEvent;
use App\Notification\NotificationManager;
use App\Notification\QueueRepository;
use App\Notification\TemplateRenderer;
use App\Repository\BotConnectionRepository;
use App\Repository\NotificationRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * Phase 4 — a contact channel on the profile is a standing opt-in.
 *
 * The promise on the profile form is "দিলে সব নোটিফিকেশন ওখানেও আসবে": every
 * notification, not just the events whose matrix happens to list the channel.
 * The matrix is the floor (webpush / fcm / telegram-for-staff) and the contact
 * columns raise it, so these tests pin the raising.
 *
 * `NotificationCoreTest` cannot cover this: it builds the manager without a
 * `BotConnectionRepository` and the container always supplies one, so the
 * Telegram half of the rule is invisible there by construction.
 *
 * Throwaway rows only — removed again in _after().
 */
final class NotificationContactFanOutTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private QueueRepository $queue;
    private BotConnectionRepository $bots;
    private NotificationManager $notify;
    private TemplateRenderer $templates;

    /** @var int[] */
    private array $userIds = [];

    /** @var int[] */
    private array $chatIds = [];

    private string $startedAt = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->queue = new QueueRepository($this->db);
        $this->bots = new BotConnectionRepository($this->db);

        $this->templates = new TemplateRenderer($this->db, new SettingsRepository($this->db));
        $this->notify = new NotificationManager(
            new NotificationRepository($this->db),
            $this->queue,
            $this->templates,
            $this->users,
            $this->db,
            $this->bots,
        );

        // Also swept by time window: an event that fans out to admins writes
        // rows on accounts that are not in $userIds.
        $this->startedAt = date('Y-m-d H:i:s', time() - 1);
    }

    protected function _after(): void
    {
        $this->db
            ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[created_at]] >= :from)')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification_queue}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();
        $this->db
            ->createCommand('DELETE FROM {{%notification}} WHERE [[created_at]] >= :from')
            ->bindValue(':from', $this->startedAt)
            ->execute();

        foreach ($this->chatIds as $chatId) {
            $this->db->createCommand()->delete('{{%bot_connection}}', ['chat_id' => $chatId])->execute();
        }

        // After the chat rows, not before: bot_connection.user_id is a foreign
        // key onto user.id, so deleting the user first trips the constraint and
        // the whole sweep aborts halfway — leaving users behind that the next
        // test's time window never catches.
        foreach ($this->userIds as $id) {
            $this->db
                ->createCommand('DELETE FROM {{%notification_delivery}} WHERE [[queue_id]] IN (SELECT [[id]] FROM {{%notification_queue}} WHERE [[user_id]] = :u)')
                ->bindValue(':u', $id)
                ->execute();
            $this->db->createCommand()->delete('{{%notification_queue}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }

        $this->userIds = [];
        $this->chatIds = [];
    }

    // ---- WhatsApp ---------------------------------------------------------

    public function testWhatsappNumberQueuesTheChannelOnAnEventThatDoesNotListIt(): void
    {
        $userId = $this->makeUser('wa');
        $this->setContacts($userId, whatsapp: '01712345678');

        // service_request.file is the strongest case to pick: the matrix is
        // in_app/fcm/webpush, so nothing but the profile can add WhatsApp — and
        // this is the one event where being left off would cost the customer
        // the thing they paid for.
        $event = NotificationEvent::SERVICE_REQUEST_FILE;
        assertNotContains(
            'whatsapp',
            NotificationEvent::spec($event)['channels'],
            'This test only means something while the matrix stays silent about WhatsApp.',
        );

        $this->notify->dispatch($event, $userId, [
            'service' => 'জন্ম নিবন্ধী', 'reference' => 'WA1',
        ]);

        assertContains('whatsapp', $this->channelsFor($userId), 'A filled-in WhatsApp number is a standing opt-in.');
    }

    public function testTelegramAlsoRaisesTheMatrixForACustomer(): void
    {
        $userId = $this->makeUser('tgraise');
        $this->setContacts($userId, telegram: '01700000009');
        $this->connectChat($userId);

        $event = NotificationEvent::SERVICE_REQUEST_FILE;
        assertNotContains('telegram', NotificationEvent::spec($event)['channels']);

        $this->notify->dispatch($event, $userId, [
            'service' => 'জন্ম নিবন্ধী', 'reference' => 'TGR1',
        ]);

        assertContains('telegram', $this->channelsFor($userId));
    }

    public function testNoWhatsappNumberMeansNoWhatsappRow(): void
    {
        $userId = $this->makeUser('nowa');

        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'WA2', 'topup_id' => 2,
        ]);

        assertNotContains('whatsapp', $this->channelsFor($userId));
    }

    public function testABlankWhatsappColumnIsNotConsent(): void
    {
        $userId = $this->makeUser('blankwa');
        // '' is what an untouched form posts. contactOn() trims and maps it to
        // null, so an empty string must not read as "opted in".
        $this->setContacts($userId, whatsapp: '   ');

        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'WA3', 'topup_id' => 3,
        ]);

        assertNotContains('whatsapp', $this->channelsFor($userId));
    }

    // ---- Telegram ---------------------------------------------------------

    public function testTelegramNeedsBothTheProfileNumberAndALiveChat(): void
    {
        $userId = $this->makeUser('tg');
        $this->setContacts($userId, telegram: '@someone');

        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'TG1', 'topup_id' => 4,
        ]);

        assertNotContains(
            'telegram',
            $this->channelsFor($userId),
            'A @handle in the profile is not an address the bot can write to; without a chat it must not queue.',
        );
    }

    public function testTelegramQueuesOnceTheBotHasAChat(): void
    {
        $userId = $this->makeUser('tgchat');
        $this->setContacts($userId, telegram: '@someone');
        $this->connectChat($userId);

        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'TG2', 'topup_id' => 5,
        ]);

        assertContains('telegram', $this->channelsFor($userId));
    }

    public function testADeactivatedChatStopsTheTelegramFanOut(): void
    {
        $userId = $this->makeUser('tgdead');
        $this->setContacts($userId, telegram: '01700000000');
        $chatId = $this->connectChat($userId);

        // The person blocked the bot: the row is kept for the audit trail but
        // marked unusable, and a blocked bot must not be queued work forever.
        $this->bots->deactivate($chatId);

        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'TG3', 'topup_id' => 6,
        ]);

        assertNotContains('telegram', $this->channelsFor($userId));
    }

    public function testAChatOnItsOwnIsNotEnoughWithoutTheProfileNumber(): void
    {
        // Guards the other direction: the connection is the *address*, the
        // profile column is the *consent*. Reversed, the bot would keep
        // writing to anyone who ever pressed /start.
        $userId = $this->makeUser('tgonly');
        $this->connectChat($userId);

        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'TG4', 'topup_id' => 7,
        ]);

        assertNotContains('telegram', $this->channelsFor($userId));
    }

    // ---- Both together, and copy ------------------------------------------

    public function testBothChannelsAtOnceAndNeitherSendsABlankMessage(): void
    {
        $userId = $this->makeUser('both');
        $this->setContacts($userId, whatsapp: '01712345679', telegram: '01700000001');
        $this->connectChat($userId);

        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, [
            'amount' => '500.00', 'method' => 'bKash', 'reference' => 'BOTH', 'topup_id' => 8,
        ]);

        $channels = $this->channelsFor($userId);
        assertContains('whatsapp', $channels);
        assertContains('telegram', $channels);

        // Asserted on the queued payload rather than on MessageTemplates: the
        // worker sends what is in the row, so a channel that renders to an
        // empty string is the thing that actually reaches a phone. topup.approved
        // has no seeded telegram copy at all, so this is the fallback path.
        foreach (['whatsapp', 'telegram'] as $channel) {
            $payload = $this->payloadFor($userId, $channel);
            assertNotNull($payload, "No queued '{$channel}' row.");
            assertNotSame('', trim((string) $payload['body']), "The '{$channel}' body renders empty.");
            assertStringContainsString(
                '500.00',
                (string) $payload['body'],
                "The '{$channel}' body lost the amount it was rendered with.",
            );
        }
    }

    // ---- Whose words are these? -------------------------------------------

    public function testACustomerIsNeverSentTheStaffWordedTelegramCopy(): void
    {
        // service_request.created carries telegram copy, and it is addressed to
        // whoever processes the order: "নতুন সার্ভিস অনুরোধ … ব্যবহারকারী #N".
        // Every event with telegram copy is an also_admins event, so that line
        // was always staff-only by construction. Fan-out breaks that
        // assumption, so the guard has to be explicit.
        $event = NotificationEvent::SERVICE_REQUEST_CREATED;
        assertTrue(
            isset(MessageTemplates::forEvent($event)['telegram']),
            'Precondition: this event really does carry staff telegram copy.',
        );

        $userId = $this->makeUser('tgstaffcopy');
        $this->setContacts($userId, telegram: '01700000003');
        $this->connectChat($userId);

        $this->notify->dispatch($event, $userId, [
            'service' => 'পাসপোর্ট', 'reference' => 'SC1', 'user_id' => $userId,
        ]);

        $body = (string) ($this->payloadFor($userId, 'telegram')['body'] ?? '');

        assertStringNotContainsString(
            'ব্যবহারকারী #',
            $body,
            'A customer was sent the staff telegram copy for their own order.',
        );
        assertStringContainsString(
            'পাসপোর্ট',
            $body,
            'The customer telegram message must still describe their order.',
        );
    }

    public function testStaffStillReceiveTheStaffWordedTelegramCopy(): void
    {
        // The other direction: the guard must not cost staff the wording that
        // tells them an order is waiting.
        $rendered = $this->templates->render(
            NotificationEvent::SERVICE_REQUEST_CREATED,
            'telegram',
            ['service' => 'পাসপোর্ট', 'reference' => 'SC2', 'user_id' => 7],
            true,
        );

        assertStringContainsString('ব্যবহারকারী #7', $rendered['body']);
    }

    public function testFanOutIsIdempotentLikeEveryOtherChannel(): void
    {
        $userId = $this->makeUser('dedupe');
        $this->setContacts($userId, whatsapp: '01712345670', telegram: '01700000002');
        $this->connectChat($userId);

        $params = ['amount' => '99.00', 'method' => 'bKash', 'reference' => 'DEDUPE', 'topup_id' => 9];
        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, $params);
        $this->notify->dispatch(NotificationEvent::TOPUP_APPROVED, $userId, $params);

        $whatsappRows = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%notification_queue}} WHERE [[user_id]] = :u AND [[channel]] = :c')
            ->bindValues([':u' => $userId, ':c' => 'whatsapp'])
            ->queryScalar();
        assertSame(1, $whatsappRows, 'A replayed approval must not enqueue a second WhatsApp row.');
    }

    // ---- Helpers ----------------------------------------------------------

    private function makeUser(string $tag): int
    {
        $id = $this->users->create([
            'username' => 'fc_' . $tag . '_' . substr(md5(uniqid('', true)), 0, 8),
            'phone' => '7' . substr(md5(uniqid('', true)), 0, 9),
            'email' => null,
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
        ]);
        $this->userIds[] = $id;
        return $id;
    }

    /**
     * The contact columns are not part of create()'s whitelist, so they are
     * stamped the way the profile form does — one column update, no re-create.
     */
    private function setContacts(int $userId, ?string $whatsapp = null, ?string $telegram = null): void
    {
        $row = [];
        if ($whatsapp !== null) {
            $row['whatsapp_no'] = $whatsapp;
        }
        if ($telegram !== null) {
            $row['telegram_no'] = $telegram;
        }
        if ($row !== []) {
            $this->db->createCommand()->update('{{%user}}', $row, ['id' => $userId])->execute();
        }
    }

    private function connectChat(int $userId): int
    {
        $chatId = random_int(700000000, 799999999);
        $this->bots->connect($userId, $chatId, 'fc_user_' . $userId);
        $this->chatIds[] = $chatId;
        return $chatId;
    }

    /** @return string[] */
    private function channelsFor(int $userId): array
    {
        $rows = $this->db
            ->createCommand('SELECT [[channel]] FROM {{%notification_queue}} WHERE [[user_id]] = :u')
            ->bindValue(':u', $userId)
            ->queryColumn();

        return array_map('strval', $rows);
    }

    /**
     * The rendered payload the worker would actually transmit for a channel.
     *
     * Read back out of the queue rather than recomputed, so the assertion is
     * about the bytes on their way to a phone and not about what the renderer
     * would return if it were called again today.
     *
     * @return array<string, mixed>|null
     */
    private function payloadFor(int $userId, string $channel): ?array
    {
        $raw = $this->db
            ->createCommand('SELECT [[payload]] FROM {{%notification_queue}} WHERE [[user_id]] = :u AND [[channel]] = :c')
            ->bindValues([':u' => $userId, ':c' => $channel])
            ->queryScalar();

        if ($raw === null || $raw === false) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
