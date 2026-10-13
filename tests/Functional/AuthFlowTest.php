<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Auth\Identity;
use App\Environment;
use App\Repository\UserRepository;
use Codeception\Util\HttpCode;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;
use function dirname;

/**
 * Functional tests for the complete authentication flow:
 * - User registration via web form
 * - Login with username/password
 * - Logout
 * - Session persistence
 */
final class AuthFlowTest extends \Codeception\Test\Unit
{
    private Container $container;
    private UserRepository $users;
    private string $username;
    private string $phone;
    private string $email;

    protected function _before(): void
    {
        $this->container = new Container(ContainerConfig::create()->withDefinitions(
            require codecept_root_dir() . 'config/common/di/db.php',
        ));

        $this->users = $this->container->get(UserRepository::class);

        $this->username = 'testflow_' . substr(md5(uniqid('', true)), 0, 8);
        $this->phone = '017' . random_int(10000000, 99999999);
        $this->email = $this->username . '@example.test';
    }

    protected function _after(): void
    {
        $row = $this->users->findByIdentifier($this->username);
        if ($row !== null) {
            $this->users->softDelete((int) $row['id']);
        }
    }

    private function createRunner(): HttpApplicationRunner
    {
        return new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
    }

    private function sendRequest(ServerRequest $request): ResponseInterface
    {
        $runner = $this->createRunner();
        $response = $runner->runAndGetResponse($request);

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $this->closeSession();

        return $response;
    }

    /**
     * Extract session cookies from Set-Cookie header
     * @return array<string, string>
     */
    private function extractSessionCookies(string $setCookie): array
    {
        $cookies = [];

        foreach (preg_split('/,(?=\s*[A-Za-z0-9_]+=)/', $setCookie) ?: [] as $chunk) {
            $pair = strtok(trim($chunk), ';');
            if ($pair === false || !str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            if (str_contains($name, 'SESSION')) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * Extract CSRF token from form HTML (supports both meta tag and input field)
     */
    private function extractCsrfToken(string $body): string
    {
        // First try input field format: <input name="_csrf" value="..."> (used in forms)
        $found = preg_match('/name="_csrf" value="([^"]+)"/', $body, $m);
        if ($found === 1) {
            return $m[1];
        }

        // Then try meta tag format: <meta name="_csrf" content="..."> (used in dashboard)
        $found = preg_match('/<meta name="_csrf" content="([^"]+)"/', $body, $m);
        if ($found === 1) {
            return $m[1];
        }

        throw new \RuntimeException('CSRF token not found in form');
    }

    /**
     * Send GET request and return response, cookies, and CSRF token
     * @return array{0: ResponseInterface, 1: array<string, string>, 2: string}
     */
    private function getWithCsrf(string $uri, array $cookies = []): array
    {
        $response = $this->sendRequest(new ServerRequest(method: 'GET', uri: $uri, cookieParams: $cookies));
        $body = $this->getBody($response);
        $newCookies = $this->extractSessionCookies((string) $response->getHeaderLine('Set-Cookie'));
        // Merge old cookies with any new ones (keep existing if not replaced)
        $cookies = array_merge($cookies, $newCookies);
        $csrf = $this->extractCsrfToken($body);
        return [$response, $cookies, $csrf];
    }

    private function sendPost(string $uri, array $fields, array $cookies = [], array $headers = []): ResponseInterface
    {
        $stream = new Stream();
        $stream->write(http_build_query($fields));

        $request = new ServerRequest(
            method: 'POST',
            uri: $uri,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers],
            cookieParams: $cookies,
            parsedBody: $fields,
            body: $stream,
        );

        return $this->sendRequest($request);
    }

    private function sendGet(string $uri, array $cookies = [], array $headers = []): ResponseInterface
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: $uri,
            headers: $headers,
            cookieParams: $cookies,
        );

        return $this->sendRequest($request);
    }

    private function getBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        return (string) $body->getContents();
    }

    /**
     * Follow a redirect and return the final response
     */
    private function followRedirect(ResponseInterface $response, array $cookies = []): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            return $response;
        }
        return $this->sendGet($location, $cookies);
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** Test successful registration via the web form */
    public function testRegisterViaWebForm(): void
    {
        [$response, $cookies, $csrf] = $this->getWithCsrf('/register');
        $body = $this->getBody($response);

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('নিবন্ধন করুন', $body);
        assertStringContainsString('পুরো নাম', $body);
        assertStringContainsString('ইউজারনেম', $body);
        assertStringContainsString('মোবাইল নম্বর', $body);
        assertStringContainsString('ইমেইল', $body);
        assertStringContainsString('পাসওয়ার্ড', $body);

        $response = $this->sendPost('/register', [
            '_csrf' => $csrf,
            'full_name' => 'Test Flow User',
            'username' => $this->username,
            'phone' => $this->phone,
            'email' => $this->email,
            'password' => 'ValidPass123!',
            'password_confirm' => 'ValidPass123!',
        ], $cookies);

        // Registration redirects to /login with flash message
        assertSame(HttpCode::FOUND, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));

        // Follow redirect to login page to see flash message
        $cookies = array_merge($cookies, $this->extractSessionCookies((string) $response->getHeaderLine('Set-Cookie')));
        $response = $this->followRedirect($response, $cookies);
        $body = $this->getBody($response);
        assertStringContainsString('নিবন্ধন সফল! এখন লগইন করুন।', $body);
    }

    /** Test registration fails with validation errors */
    public function testRegisterFailsWithValidationErrors(): void
    {
        [$response, $cookies, $csrf] = $this->getWithCsrf('/register');
        assertSame(200, $response->getStatusCode());

        // Submit empty form
        $response = $this->sendPost('/register', [
            '_csrf' => $csrf,
        ], $cookies);

        assertSame(HttpCode::OK, $response->getStatusCode());
        $body = $this->getBody($response);
        assertStringContainsString('Enter your full name (at least 2 characters).', $body);
        assertStringContainsString('Username must be at least 3 characters.', $body);
        assertStringContainsString('Enter a valid Bangladeshi mobile number (e.g. 01712345678).', $body);
        assertStringContainsString('Password must be at least 6 characters.', $body);
    }

    private function createTestUser(string $password = 'TestPass123!', string $status = 'active'): Identity
    {
        $userId = $this->users->create([
            'username' => $this->username,
            'full_name' => 'Test Flow User',
            'phone' => $this->phone,
            'email' => $this->email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
            'status' => $status,
        ]);

        return Identity::fromRow((array) $this->users->findById($userId));
    }

    /** Test login with username and password */
    public function testLoginWithUsernameAndPassword(): void
    {
        $this->createTestUser();

        [$response, $cookies, $csrf] = $this->getWithCsrf('/login');
        $body = $this->getBody($response);
        assertSame(200, $response->getStatusCode());
        assertStringContainsString('লগইন করুন', $body);
        assertStringContainsString('ইউজারনেম / মোবাইল নম্বর', $body);
        assertStringContainsString('পাসওয়ার্ড', $body);

        $response = $this->sendPost('/login', [
            '_csrf' => $csrf,
            'identifier' => $this->username,
            'password' => 'TestPass123!',
        ], $cookies);

        assertSame(HttpCode::FOUND, $response->getStatusCode());
        assertSame('/dashboard', $response->getHeaderLine('Location'));

        // Follow redirect to dashboard to see flash message
        $cookies = array_merge($cookies, $this->extractSessionCookies((string) $response->getHeaderLine('Set-Cookie')));
        $response = $this->followRedirect($response, $cookies);
        $body = $this->getBody($response);
        assertStringContainsString('লগইন সফল! স্বাগতম।', $body);
    }

    /** Test login with phone number */
    public function testLoginWithPhoneNumber(): void
    {
        $this->createTestUser();

        [$response, $cookies, $csrf] = $this->getWithCsrf('/login');

        $response = $this->sendPost('/login', [
            '_csrf' => $csrf,
            'identifier' => $this->phone,
            'password' => 'TestPass123!',
        ], $cookies);

        assertSame(HttpCode::FOUND, $response->getStatusCode());
        assertSame('/dashboard', $response->getHeaderLine('Location'));
    }

    /** Test login fails with wrong password */
    public function testLoginFailsWithWrongPassword(): void
    {
        $this->createTestUser();

        [$response, $cookies, $csrf] = $this->getWithCsrf('/login');

        $response = $this->sendPost('/login', [
            '_csrf' => $csrf,
            'identifier' => $this->username,
            'password' => 'WrongPassword123!',
        ], $cookies);

        assertSame(HttpCode::OK, $response->getStatusCode());
        $body = $this->getBody($response);
        assertStringContainsString('Invalid credentials. Please check your username and password.', $body);
    }

    /** Test login fails with unknown user */
    public function testLoginFailsWithUnknownUser(): void
    {
        [$response, $cookies, $csrf] = $this->getWithCsrf('/login');

        $response = $this->sendPost('/login', [
            '_csrf' => $csrf,
            'identifier' => 'nonexistentuser123',
            'password' => 'anypassword',
        ], $cookies);

        assertSame(HttpCode::OK, $response->getStatusCode());
        $body = $this->getBody($response);
        assertStringContainsString('Invalid credentials. Please check your username and password.', $body);
    }

    /** Test login fails on suspended account */
    public function testLoginFailsOnSuspendedAccount(): void
    {
        $this->createTestUser(status: 'suspended');

        [$response, $cookies, $csrf] = $this->getWithCsrf('/login');

        $response = $this->sendPost('/login', [
            '_csrf' => $csrf,
            'identifier' => $this->username,
            'password' => 'TestPass123!',
        ], $cookies);

        assertSame(HttpCode::OK, $response->getStatusCode());
        $body = $this->getBody($response);
        assertStringContainsString('This account has been disabled. Contact support.', $body);
    }

    /** Test logout clears session */
    public function testLogoutClearsSession(): void
    {
        $this->createTestUser();

        // Login first
        [$response, $cookies, $csrf] = $this->getWithCsrf('/login');
        $response = $this->sendPost('/login', [
            '_csrf' => $csrf,
            'identifier' => $this->username,
            'password' => 'TestPass123!',
        ], $cookies);
        assertSame(HttpCode::FOUND, $response->getStatusCode());

        // Get cookies from login response
        $loginCookies = $this->extractSessionCookies((string) $response->getHeaderLine('Set-Cookie'));
        $cookies = array_merge($cookies, $loginCookies);

        // Get dashboard to obtain CSRF token for logout
        [$response, $cookies, $csrf] = $this->getWithCsrf('/dashboard', $cookies);
        assertSame(200, $response->getStatusCode());

        // Now logout (POST required with CSRF)
        $response = $this->sendPost('/logout', ['_csrf' => $csrf], $cookies);
        assertSame(HttpCode::FOUND, $response->getStatusCode());
        assertSame('/', $response->getHeaderLine('Location'));
    }

    /** Test redirect to dashboard when already logged in */
    public function testRedirectToDashboardWhenLoggedIn(): void
    {
        $this->createTestUser();

        // Login
        [$response, $cookies, $csrf] = $this->getWithCsrf('/login');
        $response = $this->sendPost('/login', [
            '_csrf' => $csrf,
            'identifier' => $this->username,
            'password' => 'TestPass123!',
        ], $cookies);
        assertSame(HttpCode::FOUND, $response->getStatusCode());

        // Get cookies from login response
        $loginCookies = $this->extractSessionCookies((string) $response->getHeaderLine('Set-Cookie'));
        $cookies = array_merge($cookies, $loginCookies);

        // Try to access login page again - should redirect to dashboard
        $response = $this->sendGet('/login', $cookies);
        assertSame(HttpCode::FOUND, $response->getStatusCode());
        assertSame('/dashboard', $response->getHeaderLine('Location'));

        // Try to access register page - should redirect to dashboard
        $response = $this->sendGet('/register', $cookies);
        assertSame(HttpCode::FOUND, $response->getStatusCode());
        assertSame('/dashboard', $response->getHeaderLine('Location'));
    }

    private function debugResponseStderr(string $label, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        $location = $response->getHeaderLine('Location');
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $body = $this->getBody($response);
        fwrite(STDERR, "\n=== $label ===\n");
        fwrite(STDERR, "Status: $status\n");
        fwrite(STDERR, "Location: $location\n");
        fwrite(STDERR, "Set-Cookie: $setCookie\n");
        fwrite(STDERR, "Body (first 500 chars): " . substr($body, 0, 500) . "\n");
    }

    private function debugResponse(string $label, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        $location = $response->getHeaderLine('Location');
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $body = $this->getBody($response);
        echo "\n=== $label ===\n";
        echo "Status: $status\n";
        echo "Location: $location\n";
        echo "Set-Cookie: $setCookie\n";
        echo "Body (first 500 chars): " . substr($body, 0, 500) . "\n";
    }
}