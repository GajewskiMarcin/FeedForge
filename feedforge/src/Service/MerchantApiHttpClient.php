<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\AccountRepository;
use Google\Auth\Credentials\UserRefreshCredentials;

/**
 * Direct REST/JSON client for Google Merchant API.
 *
 * Why we have this in addition to the official PHP SDK:
 * - The SDK depends on google/protobuf which has two competing implementations:
 *   pure-PHP (`composer require google/protobuf`) and a C extension (`ext-protobuf`).
 * - Pure-PHP has a "Fail to push limit" bug on Merchant API responses.
 * - The C extension has descriptor-format conflicts with the composer pure-PHP package
 *   that google/shopping-common-protos depends on, so we can't easily run with one
 *   without the other.
 * - To eliminate the dependency entirely, we call the REST endpoints ourselves and
 *   parse plain JSON. No protobuf in either direction.
 *
 * We still use `google/auth`'s UserRefreshCredentials to mint access tokens from the
 * refresh token stored locally (encrypted) — that's plain OAuth, no protobuf.
 *
 * The client is intentionally kept thin — sub-API specific request shapes are built
 * by the calling services (DataSourceService, ProductService, etc.).
 */
class MerchantApiHttpClient
{
    private const API_HOST = 'https://merchantapi.googleapis.com';
    private const SCOPE = 'https://www.googleapis.com/auth/content';
    private const REQUEST_TIMEOUT = 30;

    /** Cache of access tokens per shop within a single request. */
    private array $tokenCache = [];

    public function __construct(
        private readonly TokenEncryption $tokenEncryption,
        private readonly AccountRepository $accountRepository,
    ) {
    }

    /**
     * GET request to a Merchant API endpoint.
     *
     * @param string $path  Path relative to the API host (must start with `/`).
     * @param array  $query Query string parameters.
     * @return array Decoded JSON response (associative array).
     * @throws \RuntimeException on HTTP error or invalid JSON.
     */
    public function get(int $shopId, string $path, array $query = []): array
    {
        return $this->request($shopId, 'GET', $path, $query, null);
    }

    /**
     * POST request with JSON body.
     *
     * @param array $body Will be json_encoded.
     */
    public function post(int $shopId, string $path, array $body, array $query = []): array
    {
        return $this->request($shopId, 'POST', $path, $query, $body);
    }

    /**
     * PATCH request with JSON body.
     */
    public function patch(int $shopId, string $path, array $body, array $query = []): array
    {
        return $this->request($shopId, 'PATCH', $path, $query, $body);
    }

    /**
     * DELETE request. Most Merchant API delete endpoints return an empty body on success.
     */
    public function delete(int $shopId, string $path, array $query = []): void
    {
        $this->request($shopId, 'DELETE', $path, $query, null);
    }

    /**
     * Iterate all pages of a paginated list endpoint.
     *
     * @param string $path           Relative API path.
     * @param string $itemsKey       Top-level key in response that contains the array of items
     *                               (e.g. "dataSources", "products", "accountIssues").
     * @param array  $initialQuery   Initial query parameters (page_size etc.).
     * @return iterable<int, array>  Yields each item as an associative array.
     */
    public function listAllPages(int $shopId, string $path, string $itemsKey, array $initialQuery = []): iterable
    {
        $query = $initialQuery;

        while (true) {
            $response = $this->get($shopId, $path, $query);

            foreach ($response[$itemsKey] ?? [] as $item) {
                yield $item;
            }

            $nextPageToken = $response['nextPageToken'] ?? '';
            if ($nextPageToken === '') {
                return;
            }
            $query['pageToken'] = $nextPageToken;
        }
    }

    /**
     * Get a fresh OAuth access token for the given shop, refreshing if necessary.
     * Cached per-request so a single sync doesn't make 100 token-refresh calls.
     */
    public function getAccessToken(int $shopId): string
    {
        if (isset($this->tokenCache[$shopId])) {
            return $this->tokenCache[$shopId];
        }

        $credentials = $this->buildCredentials($shopId);
        $tokenData = $credentials->fetchAuthToken();

        if (empty($tokenData['access_token'])) {
            $err = $tokenData['error_description'] ?? $tokenData['error'] ?? 'unknown OAuth error';
            throw new \RuntimeException('Failed to obtain access token: ' . $err);
        }

        return $this->tokenCache[$shopId] = (string) $tokenData['access_token'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function request(int $shopId, string $method, string $path, array $query, ?array $body): array
    {
        $token = $this->getAccessToken($shopId);

        $url = self::API_HOST . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        if ($body !== null) {
            $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonBody === false) {
                throw new \RuntimeException('Failed to encode request body as JSON: ' . json_last_error_msg());
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $rawResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new \RuntimeException(sprintf('Merchant API %s %s failed: %s', $method, $path, $curlError));
        }

        // 2xx with empty body (typical for DELETE) → return empty array.
        if ($httpCode >= 200 && $httpCode < 300 && trim((string) $rawResponse) === '') {
            return [];
        }

        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                'Merchant API %s %s: invalid JSON response (HTTP %d): %s',
                $method,
                $path,
                $httpCode,
                substr((string) $rawResponse, 0, 500)
            ));
        }

        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? sprintf('HTTP %d', $httpCode);
            $details = $decoded['error']['details'] ?? null;

            $exception = new MerchantApiException(
                sprintf('Merchant API %s %s failed: %s', $method, $path, $message),
                $httpCode
            );
            $exception->setApiResponse($decoded);
            $exception->setHttpStatus($httpCode);

            if ($details !== null) {
                // Surface the first detail as an extra hint in the message.
                $firstDetail = $details[0]['reason'] ?? $details[0]['domain'] ?? null;
                if ($firstDetail !== null && is_string($firstDetail)) {
                    $exception->setApiReason($firstDetail);
                }
            }

            throw $exception;
        }

        return $decoded;
    }

    /**
     * Build a UserRefreshCredentials instance from the encrypted refresh token in the database.
     */
    private function buildCredentials(int $shopId): UserRefreshCredentials
    {
        $account = $this->accountRepository->findByShop($shopId);

        if (!$account || empty($account['refresh_token'])) {
            throw new \RuntimeException(
                'No Google account connected for this shop. Please connect in Configuration.'
            );
        }

        $ivData = json_decode($account['encryption_iv'] ?? '{}', true) ?: [];
        $refreshToken = $this->tokenEncryption->decrypt(
            $account['refresh_token'],
            $ivData['refresh'] ?? ''
        );

        $clientId = (string) \Configuration::get('FEEDFORGE_GOOGLE_CLIENT_ID');
        $clientSecret = (string) \Configuration::get('FEEDFORGE_GOOGLE_CLIENT_SECRET');

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException(
                'Google OAuth client credentials are not configured. Please set them in Configuration.'
            );
        }

        return new UserRefreshCredentials(
            self::SCOPE,
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]
        );
    }
}
