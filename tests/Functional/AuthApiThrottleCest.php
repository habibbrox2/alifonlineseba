<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\UserRepository;
use App\Tests\Support\Database;
use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertSame;

/**
 * POST /api/auth/login must inherit the web login's brute-force throttle: a
 * wrong password five times, and the sixth attempt answers 429 with the
 * Bengali copy — whether or not the attacker finally typed it correctly, and
 * whether or not the identifier exists at all.
 *
 * Every attempt uses a per-run unique identifier. The throttle counters live
 * in the shared file cache, so a fixed key would poison other tests: the next
 * run's first attempt would start out already locked out.
 */
final class AuthApiThrottleCest
{
    private const THROTTLED_MESSAGE = 'অনেকবার ভুল চেষ্টা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।';
    private const PASSWORD = 'Str0ng!pass';

    private string $suffix = '';

    /** @var int[] */
    private array $createdUserIds = [];

    public function _before(): void
    {
        $this->suffix = 'a' . substr(md5(uniqid('', true)), 0, 10);
    }

    public function _after(): void
    {
        $db = Database::connection();
        foreach ($this->createdUserIds as $id) {
            // FK order: tokens and logs point at the row about to go.
            $db->createCommand()->delete('{{%api_token}}', ['user_id' => $id])->execute();
            foreach (['{{%activity_log}}', '{{%notification}}', '{{%notification_queue}}'] as $table) {
                $db->createCommand("DELETE FROM {$table} WHERE [[user_id]] = :u")
                    ->bindValue(':u', $id)
                    ->execute();
            }
            $db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->createdUserIds = [];
    }

    /**
     * The lockout must bite even when the attacker finally types the right
     * password: five wrong attempts, then the correct one refused with 429 —
     * before the throttle existed, that sixth POST answered 200 and issued
     * tokens. A different, untouched identifier on the same IP must still get
     * its own ordinary 401, so the key is per-identifier, not per-IP.
     */
    public function correctPasswordIsStillRefusedAfterFiveWrongAttempts(FunctionalTester $tester): void
    {
        $identifier = 'thr' . $this->suffix;
        $this->makeUser($identifier);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->login($tester, $identifier, 'wrong-password-' . $attempt);
            assertSame(401, $response->getStatusCode(), "Wrong-password attempt $attempt must be refused with 401.");
        }

        $lockedOut = $this->login($tester, $identifier, self::PASSWORD);
        assertSame(
            429,
            $lockedOut->getStatusCode(),
            'After five failures even the correct password must be refused with 429.',
        );
        assertSame(self::THROTTLED_MESSAGE, $this->decode($lockedOut)['message']);

        $other = $this->login($tester, 'other' . $this->suffix, 'wrong-password');
        assertSame(
            401,
            $other->getStatusCode(),
            'A different identifier on the same IP keeps its own counter — no lockout leak.',
        );
    }

    /**
     * No account needed: an unknown identifier fails identically, so every
     * attempt must be counted and guessing a username is throttled too.
     */
    public function unknownIdentifierIsThrottledAfterFiveWrongAttempts(FunctionalTester $tester): void
    {
        $identifier = 'unknownthr' . $this->suffix;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->login($tester, $identifier, 'wrong-password-' . $attempt);
            assertSame(401, $response->getStatusCode(), "Wrong-password attempt $attempt must be refused with 401.");
        }

        $lockedOut = $this->login($tester, $identifier, 'wrong-password-6');
        assertSame(
            429,
            $lockedOut->getStatusCode(),
            'The sixth failed attempt must answer 429, not another 401.',
        );
        assertSame(self::THROTTLED_MESSAGE, $this->decode($lockedOut)['message']);
    }

    private function makeUser(string $username): int
    {
        $users = new UserRepository(Database::connection());
        $id = $users->create([
            'username' => $username,
            'phone' => '7' . substr(md5($username), 0, 9),
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'admin',
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    /**
     * A real POST through the app — CSRF gate, router, action — the same path
     * the Android app's login takes. The placeholder bearer header is what
     * marks the request machine-shaped (no cross-site form can set it), the
     * same trick AdminApiRouterCest uses to reach the login at all.
     */
    private function login(FunctionalTester $tester, string $identifier, string $password): ResponseInterface
    {
        $fields = ['identifier' => $identifier, 'password' => $password];
        $stream = new Stream();
        $stream->write(http_build_query($fields));

        $response = $tester->sendRequest(new ServerRequest(
            method: 'POST',
            uri: '/api/auth/login',
            headers: [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Bearer throttle-probe',
            ],
            parsedBody: $fields,
            body: $stream,
        ));

        // Each sendRequest() boots a fresh runner in this same process; the
        // session left open would be inherited by the next request.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            $_SESSION = [];
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return json_decode((string) $body->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }
}
