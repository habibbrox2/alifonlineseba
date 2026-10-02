<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Web\Api\NotificationsApiAction;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;

/**
 * The JSON API behind the header notification dropdown.
 *
 * The dropdown is optimistic — it drops the badge and dims the row before the
 * response arrives — so the assertions here concentrate on what the client
 * trusts: that the payload always carries the counts it re-renders from, that
 * marking one row read only touches that row, and that a user cannot mark (or
 * even learn the existence of) somebody else's notification. A cross-user
 * PATCH is the interesting failure mode: it answers 200 with a count, so a
 * broken WHERE clause would corrupt the badge rather than visibly fail.
 *
 * Throwaway rows only, all removed again in _after().
 */
final class NotificationsApiTest extends \Codeception\Test\Unit
{
    private ConnectionInterface $db;
    private UserRepository $users;
    private NotificationRepository $notifications;
    private NotificationsApiAction $action;

    /** @var int[] */
    private array $userIds = [];
    private string $suffix = '';

    protected function _before(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));
        $this->db = $container->get(ConnectionInterface::class);
        $this->users = new UserRepository($this->db);
        $this->notifications = new NotificationRepository($this->db);
        $this->action = new NotificationsApiAction($this->notifications);

        $this->suffix = 'n' . substr(md5(uniqid('', true)), 0, 10);
    }

    protected function _after(): void
    {
        foreach ($this->userIds as $id) {
            $this->db->createCommand()->delete('{{%notification}}', ['user_id' => $id])->execute();
            $this->db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
    }

    private function makeUser(string $tag = 'a'): Identity
    {
        $id = $this->users->create([
            'username' => 'nt_' . $tag . '_' . $this->suffix,
            'phone' => '7' . substr(md5($this->suffix . $tag), 0, 9),
            'email' => 'nt_' . $tag . '_' . $this->suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'balance' => 0,
        ]);
        $this->userIds[] = $id;

        return Identity::fromRow((array) $this->users->findById($id));
    }

    private function seed(Identity $user, string $title, string $type = 'info'): int
    {
        return $this->notifications->create(
            $user->id,
            $title,
            'বার্তা: ' . $title,
            $type,
        );
    }

    /** Drive the action the way the router does, with the id argument bound. */
    private function call(Identity $user, string $method, string $uri, ?int $notificationId = null): ResponseInterface
    {
        $request = (new ServerRequest($method, $uri))->withAttribute('identity', $user);
        $route = new CurrentRoute();
        $route->setRouteWithArguments(
            Route::methods(['GET', 'POST', 'PATCH'], '/api/notifications')->name('api-notifications'),
            $notificationId === null ? [] : ['id' => (string) $notificationId],
        );

        return ($this->action)($request, $route);
    }

    /** @return array{success: bool, message: string, data: array} */
    private function decode(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    // ---- Listing -----------------------------------------------------------

    public function testListReturnsRowsAndBothCounts(): void
    {
        $user = $this->makeUser();
        $this->seed($user, 'অর্ডার গৃহীত হয়েছে');
        $this->seed($user, 'সার্ভিস সম্পন্ন হয়েছে', 'success');
        $third = $this->seed($user, 'টপ-আপ বাতিল হয়েছে', 'warning');
        $this->notifications->markRead($third, $user->id);

        $payload = $this->decode($this->call($user, 'GET', '/api/notifications'));

        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('notifications', $payload['data']);
        $this->assertArrayHasKey('total', $payload['data']);
        $this->assertArrayHasKey('unread', $payload['data']);
        $this->assertCount(3, $payload['data']['notifications']);
        $this->assertSame(3, $payload['data']['total']);
        $this->assertSame(2, $payload['data']['unread'], 'The badge count must exclude rows already read.');
    }

    public function testListNeverLeaksAnotherUsersRows(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser('b');
        $this->seed($user, 'আমার নোটিফিকেশন');
        $this->seed($other, 'অন্যের গোপন নোটিফিকেশন');

        $payload = $this->decode($this->call($user, 'GET', '/api/notifications'));
        $titles = array_column($payload['data']['notifications'], 'title');

        $this->assertSame(['আমার নোটিফিকেশন'], $titles);
        $this->assertSame(1, $payload['data']['total']);
    }

    public function testListIsNewestFirstAndPaginates(): void
    {
        $user = $this->makeUser();
        // The dropdown asks for the default page size, which is 15 — so a
        // second page only exists once the user has more than that.
        for ($i = 1; $i <= 16; $i++) {
            $this->seed($user, 'নোটিফিকেশন ' . $i);
        }

        $page = $this->decode($this->call($user, 'GET', '/api/notifications?page=1'))['data'];
        $this->assertCount(15, $page['notifications']);
        $this->assertSame('নোটিফিকেশন 16', $page['notifications'][0]['title'], 'Newest first.');
        $this->assertSame(16, $page['total'], 'The total is for the user, not the page.');

        $second = $this->decode($this->call($user, 'GET', '/api/notifications?page=2'))['data'];
        $this->assertCount(1, $second['notifications']);
        $this->assertSame('নোটিফিকেশন 1', $second['notifications'][0]['title'], 'The oldest row falls onto page 2.');
        $this->assertSame(16, $second['total'], 'Every page reports the same total.');
    }

    public function testPageBelowOneIsClampedRatherThanErroring(): void
    {
        $user = $this->makeUser();
        $this->seed($user, 'এক');

        $payload = $this->decode($this->call($user, 'GET', '/api/notifications?page=0'))['data'];

        $this->assertCount(1, $payload['notifications'], 'A hand-typed page=0 must not read as an empty list.');
    }

    public function testEmptyUserGetsTheEmptyMessage(): void
    {
        $user = $this->makeUser();

        $payload = $this->decode($this->call($user, 'GET', '/api/notifications'));

        $this->assertSame([], $payload['data']['notifications']);
        $this->assertSame(0, $payload['data']['total']);
        $this->assertSame(0, $payload['data']['unread']);
        $this->assertSame('কোনো নোটিফিকেশন নেই।', $payload['message']);
    }

    // ---- Mark as read ------------------------------------------------------

    public function testMarkReadStampsOneRowAndReportsTheNewCount(): void
    {
        $user = $this->makeUser();
        $sibling = $this->seed($user, 'এক');
        $target = $this->seed($user, 'দুই');

        $payload = $this->decode($this->call($user, 'PATCH', '/api/notifications/' . $target . '/read', $target));

        $this->assertTrue($payload['success']);
        $this->assertSame($target, $payload['data']['id'], 'The response echoes the row the client clicked.');
        $this->assertSame(1, $payload['data']['unread'], 'The optimistic badge count must be corrected by the server.');
        $this->assertNotNull(
            $this->readAt($user, $target),
            'markRead() must persist read_at, not just answer.',
        );
        $this->assertNull(
            $this->readAt($user, $sibling),
            'Marking one row read must not touch the others — that is what the badge would miscount.',
        );
    }

    public function testMarkReadOnAnotherUsersRowChangesNothing(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser('b');
        $foreign = $this->seed($other, 'অন্যের নোটিফিকেশন');

        $payload = $this->decode($this->call($user, 'PATCH', '/api/notifications/' . $foreign . '/read', $foreign));

        $this->assertNull(
            $this->readAt($other, $foreign),
            'A user must not be able to read another user\'s notification by guessing its id.',
        );
        $this->assertSame(0, $payload['data']['unread'], 'The count is the caller\'s own, so a no-op still answers 0.');
    }

    public function testMarkReadIsIdempotent(): void
    {
        $user = $this->makeUser();
        $id = $this->seed($user, 'এক');
        $this->notifications->markRead($id, $user->id);

        $payload = $this->decode($this->call($user, 'PATCH', '/api/notifications/' . $id . '/read', $id));

        $this->assertTrue($payload['success']);
        $this->assertSame(0, $payload['data']['unread']);
    }

    // ---- Mark all read -----------------------------------------------------

    public function testReadAllClearsEveryUnreadRow(): void
    {
        $user = $this->makeUser();
        $this->seed($user, 'এক');
        $this->seed($user, 'দুই');
        $this->seed($user, 'তিন', 'warning');

        $payload = $this->decode($this->call($user, 'POST', '/api/notifications/read-all'));

        $this->assertTrue($payload['success']);
        $this->assertSame(0, $payload['data']['unread']);
        $this->assertSame('সব নোটিফিকেশন পড়া হয়েছে।', $payload['message']);
        $this->assertSame(0, $this->notifications->unreadCount($user->id));
    }

    public function testReadAllLeavesOtherUsersUntouched(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser('b');
        $this->seed($user, 'আমার');
        $foreign = $this->seed($other, 'অন্যের');

        $this->call($user, 'POST', '/api/notifications/read-all');

        $this->assertNull($this->readAt($other, $foreign), 'read-all must stay scoped to the caller.');
        $this->assertSame(1, $this->notifications->unreadCount($other->id));
    }

    public function testReadAllPreservesTheAlreadyReadTimestamp(): void
    {
        $user = $this->makeUser();
        $older = $this->seed($user, 'পুরনো');
        $this->notifications->markRead($older, $user->id);
        $stamp = $this->readAt($user, $older);
        $this->seed($user, 'নতুন');

        $this->call($user, 'POST', '/api/notifications/read-all');

        $this->assertSame($stamp, $this->readAt($user, $older), 'read-all must not rewrite rows it did not change.');
    }

    // ---- Payload shape -----------------------------------------------------

    public function testRowsCarryTheFieldsTheDropdownRenders(): void
    {
        $user = $this->makeUser();
        $this->seed($user, 'তথ্য', 'info');
        $this->seed($user, 'সফলতা', 'success');
        $this->seed($user, 'সতর্কতা', 'warning');

        $payload = $this->decode($this->call($user, 'GET', '/api/notifications'));
        $row = (array) $payload['data']['notifications'][0];

        // app.js reads exactly these four and nothing else: iconHref() needs
        // type, the "নতুন" badge needs read_at, notificationTime() needs
        // created_at. A renamed column would render a blank dropdown, not throw.
        foreach (['id', 'title', 'message', 'type', 'read_at', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $row, 'The dropdown cannot render without ' . $field);
        }
        $this->assertSame(
            ['info', 'success', 'warning'],
            array_reverse(array_column($payload['data']['notifications'], 'type')),
            'All three tones must survive the JSON round trip; the JS map has no other keys.',
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at']);
    }

    // ---- Helpers -----------------------------------------------------------

    private function readAt(Identity $user, int $id): ?string
    {
        $value = $this->db
            ->createCommand('SELECT [[read_at]] FROM {{%notification}} WHERE [[id]] = :id AND [[user_id]] = :uid')
            ->bindValue(':id', $id)
            ->bindValue(':uid', $user->id)
            ->queryScalar();

        return $value === null || $value === false ? null : (string) $value;
    }
}
