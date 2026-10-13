<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Service for scraping web pages using ScraperAPI.
 *
 * ScraperAPI handles proxies, CAPTCHAs, and anti-bot measures automatically.
 * Provide your ScraperAPI key via the SCRAPERAPI_KEY environment variable.
 */
final class ScraperApiService
{
    private const BASE_URL = 'https://api.scraperapi.com/';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly UriFactoryInterface $uriFactory,
        private readonly string $apiKey,
    ) {}

    /**
     * Scrape a URL and return the HTML content.
     *
     * @param array<string, string> $options Optional ScraperAPI parameters:
     *   - 'render' => 'true' to render JavaScript (premium feature)
     *   - 'country_code' => 'us' to set proxy country
     *   - 'premium' => 'true' for premium proxies
     *   - 'session_number' => '123' to reuse the same IP
     *   - 'keep_headers' => 'true' to preserve response headers
     *   - 'device_type' => 'mobile' or 'desktop'
     * @return string The scraped HTML content
     * @throws \RuntimeException If the request fails
     */
    public function scrape(string $url, array $options = []): string
    {
        $query = array_merge(['api_key' => $this->apiKey, 'url' => $url], $options);
        $uri = $this->uriFactory->createUri(self::BASE_URL . '?' . http_build_query($query));

        $request = $this->requestFactory->createRequest('GET', $uri);
        $response = $this->httpClient->sendRequest($request);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status !== 200) {
            throw new \RuntimeException(
                "ScraperAPI request failed with status {$status}: {$body}"
            );
        }

        return $body;
    }

    /**
     * Scrape a URL and return the response as an associative array (for JSON responses).
     *
     * @param array<string, string> $options Optional ScraperAPI parameters (see scrape())
     * @return array<string, mixed> The decoded JSON response
     * @throws \RuntimeException If the request fails or response is not valid JSON
     */
    public function scrapeJson(string $url, array $options = []): array
    {
        $body = $this->scrape($url, $options);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('ScraperAPI returned invalid JSON: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Check if the API key is configured.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Get the account usage/credit information.
     *
     * @return array{requests_used: int, requests_limit: int, concurrent_requests: int}
     * @throws \RuntimeException If the request fails
     */
    public function getAccountUsage(): array
    {
        $uri = $this->uriFactory->createUri(self::BASE_URL . 'account?api_key=' . $this->apiKey);
        $request = $this->requestFactory->createRequest('GET', $uri);
        $response = $this->httpClient->sendRequest($request);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status !== 200) {
            throw new \RuntimeException(
                "ScraperAPI account request failed with status {$status}: {$body}"
            );
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('ScraperAPI returned invalid JSON for account: ' . json_last_error_msg());
        }

        return [
            'requests_used' => (int) ($data['requestCount'] ?? 0),
            'requests_limit' => (int) ($data['planLimit'] ?? 0),
            'concurrent_requests' => (int) ($data['concurrentRequests'] ?? 0),
        ];
    }
}