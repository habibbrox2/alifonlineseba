<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Tests\Support\Database;

final class ApiTokenRotationTest extends \Codeception\Test\Unit
{
    private UserRepository $users;
    private ApiTokenRepository $tokens;
    private array $userIds = [];

    protected function _before(): void
    {
        $db = Database::connection();
        $this->users = new UserRepository($db);
        $this->tokens = new ApiTokenRepository($db);
    }

    protected function _after(): void
    {
        $db = Database::connection();
        foreach ($this->userIds as $id) {
            $db->createCommand()->delete('{{%api_token}}', ['user_id' => $id])->execute();
            $db->createCommand()->delete('{{%activity_log}}', ['user_id' => $id])->execute();
            $db->createCommand()->delete('{{%user}}', ['id' => $id])->execute();
        }
        $this->userIds = [];
    }

    public function testRefreshTokenCanOnlyBeRotatedOnce(): void
    {
        $userId = $this->makeActiveUser();
        $original = $this->tokens->issue($userId, 'rotation-test');

        $rotated = $this->tokens->rotate($original['refresh']);

        $this->assertIsArray($rotated);
        $this->assertNotEmpty($rotated['token']);
        $this->assertNotEmpty($rotated['refresh']);
        $this->assertNull($this->tokens->rotate($original['refresh']));
        $this->assertSame($userId, $this->tokens->resolve($original['token']));
        $this->assertSame($userId, $this->tokens->resolve($rotated['token']));

        $count = (int) Database::connection()
            ->createCommand('SELECT COUNT(*) FROM {{%api_token}} WHERE [[user_id]] = :id')
            ->bindValue(':id', $userId)
            ->queryScalar();

        $this->assertSame(4, $count, 'A successful rotation must add exactly one access/refresh pair.');
    }

    public function testDisabledAccountCannotRotateRefreshToken(): void
    {
        $userId = $this->makeActiveUser();
        $original = $this->tokens->issue($userId, 'disabled-account-test');

        $this->users->update($userId, ['status' => 'disabled']);

        $this->assertNull($this->tokens->rotate($original['refresh']));
        $count = (int) Database::connection()
            ->createCommand('SELECT COUNT(*) FROM {{%api_token}} WHERE [[user_id]] = :id')
            ->bindValue(':id', $userId)
            ->queryScalar();
        $this->assertSame(2, $count, 'A disabled account must not receive replacement tokens.');
    }

    private function makeActiveUser(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $id = $this->users->create([
            'username' => 'token_rotation_' . $suffix,
            'phone' => '7' . substr(md5($suffix), 0, 9),
            'email' => 'token_rotation_' . $suffix . '@example.test',
            'password_hash' => password_hash('Str0ng!pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'admin',
            'balance' => 0,
        ]);
        $this->userIds[] = $id;

        return $id;
    }
}
