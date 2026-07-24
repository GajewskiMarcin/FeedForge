<?php

declare(strict_types=1);

namespace FeedForge\Service;

use FeedForge\Repository\AccountRepository;
use Google\Auth\OAuth2;

/**
 * Google OAuth + Merchant Center connection helper.
 *
 * Handles:
 * - OAuth 2.0 authorization-code flow (consent URL → token exchange → encrypted token storage)
 * - Account lookup helpers (merchant ID, email, connection state)
 * - Token revocation on disconnect
 *
 * v2.0.1: This class no longer constructs Merchant API service clients — those
 * went away with the protobuf SDK migration. Instead, MerchantApiHttpClient
 * handles all API calls via raw REST. This class only does OAuth setup.
 */
class GoogleApiClient
{
    /** OAuth scope — covers both Content API and Merchant API. */
    private const SCOPE = 'https://www.googleapis.com/auth/content';

    private const AUTHORIZATION_URI = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_CREDENTIAL_URI = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URI = 'https://oauth2.googleapis.com/revoke';

    public function __construct(
        private readonly TokenEncryption $tokenEncryption,
        private readonly AccountRepository $accountRepository,
        private readonly MerchantApiHttpClient $http,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OAuth 2.0 flow
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate the OAuth 2.0 authorization URL the user must visit to grant access.
     */
    public function getAuthUrl(string $redirectUri, string $state): string
    {
        $oauth = $this->buildOAuth2($redirectUri);
        $oauth->setState($state);

        return (string) $oauth->buildFullAuthorizationUri([
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    /**
     * Exchange an authorization code for tokens, encrypt and persist.
     *
     * @return array{email: string, merchantId: string|null, merchantName: string|null}
     */
    public function handleCallback(string $code, string $redirectUri, int $shopId): array
    {
        $oauth = $this->buildOAuth2($redirectUri);
        $oauth->setCode($code);

        $tokenData = $oauth->fetchAuthToken();

        if (isset($tokenData['error'])) {
            throw new \RuntimeException(
                'OAuth error: ' . ($tokenData['error_description'] ?? $tokenData['error'])
            );
        }

        if (empty($tokenData['access_token'])) {
            throw new \RuntimeException('OAuth response missing access_token');
        }

        $accessEncrypted = $this->tokenEncryption->encrypt($tokenData['access_token']);
        $refreshEncrypted = isset($tokenData['refresh_token'])
            ? $this->tokenEncryption->encrypt($tokenData['refresh_token'])
            : ['ciphertext' => '', 'iv' => ''];

        $expiresAt = isset($tokenData['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int) $tokenData['expires_in'])
            : null;

        // First store with empty merchant info so MerchantApiHttpClient can look up credentials.
        $this->accountRepository->upsert($shopId, [
            'merchant_id' => '',
            'merchant_name' => '',
            'email' => '',
            'access_token' => $accessEncrypted['ciphertext'],
            'refresh_token' => $refreshEncrypted['ciphertext'],
            'token_expires_at' => $expiresAt,
            'encryption_iv' => json_encode([
                'access' => $accessEncrypted['iv'],
                'refresh' => $refreshEncrypted['iv'],
            ]),
            'active' => 1,
        ]);

        // Probe Merchant API to discover merchant ID + name.
        $accountInfo = $this->fetchAccountInfo($shopId);
        // Probe userinfo for email (separate Google endpoint).
        $accountInfo['email'] = $this->fetchUserEmail($tokenData['access_token']);

        if ($accountInfo['merchantId'] !== null || $accountInfo['email'] !== '') {
            $this->accountRepository->upsert($shopId, [
                'merchant_id' => (string) ($accountInfo['merchantId'] ?? ''),
                'merchant_name' => (string) ($accountInfo['merchantName'] ?? ''),
                'email' => $accountInfo['email'],
            ]);
        }

        return $accountInfo;
    }

    /**
     * Disconnect: revoke the refresh token and remove the local account record.
     */
    public function disconnect(int $shopId): void
    {
        $account = $this->accountRepository->findByShop($shopId);

        if ($account && !empty($account['refresh_token'])) {
            try {
                $ivData = json_decode($account['encryption_iv'] ?? '{}', true) ?: [];
                $refreshToken = $this->tokenEncryption->decrypt(
                    $account['refresh_token'],
                    $ivData['refresh'] ?? ''
                );

                $this->revokeToken($refreshToken);
            } catch (\Throwable $e) {
                // Swallow — disconnect must always succeed locally.
            }
        }

        $this->accountRepository->delete($shopId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Account / connection state
    // ─────────────────────────────────────────────────────────────────────────

    public function getMerchantId(int $shopId): string
    {
        $account = $this->accountRepository->findByShop($shopId);

        if (!$account || empty($account['merchant_id'])) {
            throw new \RuntimeException('No Merchant Center account connected for this shop');
        }

        return (string) $account['merchant_id'];
    }

    public function isConnected(int $shopId): bool
    {
        $account = $this->accountRepository->findByShop($shopId);

        return $account !== null
            && !empty($account['refresh_token'])
            && (int) ($account['active'] ?? 0) === 1;
    }

    /**
     * @return array{email: string, merchantId: string, merchantName: string, tokenExpiresAt: ?string, active: bool, gcpRegistered: bool}|null
     */
    public function getAccountInfo(int $shopId): ?array
    {
        $account = $this->accountRepository->findByShop($shopId);

        if (!$account) {
            return null;
        }

        return [
            'email' => $account['email'] ?? '',
            'merchantId' => $account['merchant_id'] ?? '',
            'merchantName' => $account['merchant_name'] ?? '',
            'tokenExpiresAt' => $account['token_expires_at'] ?? null,
            'active' => (bool) ($account['active'] ?? false),
            'gcpRegistered' => (bool) ($account['gcp_registered'] ?? false),
        ];
    }

    /**
     * List all Merchant Center accounts the connected user has access to.
     * Used by the UI when the auto-detection picked the wrong account or none at all,
     * so the user can pick the right one from a dropdown.
     *
     * @return array<int, array{merchantId: string, accountName: string, name: string}>
     */
    public function listGoogleAccounts(int $shopId): array
    {
        $accounts = [];

        try {
            $response = $this->http->get($shopId, '/accounts/v1/accounts');
            foreach ($response['accounts'] ?? [] as $account) {
                $name = (string) ($account['name'] ?? '');
                if (preg_match('#^accounts/(\d+)$#', $name, $matches)) {
                    $accounts[] = [
                        'merchantId' => $matches[1],
                        'accountName' => (string) ($account['accountName'] ?? ''),
                        'name' => $name,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Endpoint may error for users with only sub-account access under an MCA, or while
            // Merchant API is still propagating after enabling. Caller offers manual entry as fallback.
        }

        return $accounts;
    }

    /**
     * Save the Merchant ID chosen by the user (either from the dropdown or typed in manually).
     * Tries to look up the account's friendly name from the live API; falls back to ID-only.
     */
    public function setMerchantId(int $shopId, string $merchantId): void
    {
        $merchantId = trim($merchantId);
        if ($merchantId === '' || !ctype_digit($merchantId)) {
            throw new \InvalidArgumentException('Merchant ID must be a non-empty numeric string.');
        }

        $merchantName = '';
        try {
            $account = $this->http->get($shopId, sprintf('/accounts/v1/accounts/%s', $merchantId));
            $merchantName = (string) ($account['accountName'] ?? '');
        } catch (\Throwable $e) {
            // Friendly name lookup failed — store ID alone.
        }

        $this->accountRepository->upsert($shopId, [
            'merchant_id' => $merchantId,
            'merchant_name' => $merchantName,
        ]);
    }

    /**
     * Register the connected GCP project as a Merchant API developer for the given shop's
     * merchant account. This is a one-time per-project step; after it succeeds every other
     * Merchant API call starts working (listDataSources etc.).
     *
     * Endpoint: POST /accounts/v1/accounts/{merchantId}/developerRegistration:registerGcp
     * Body:     {"developer_email": "user@example.com"}
     *
     * @param string|null $developerEmail Optional override for the email. If null, tries to
     *                                     read from the account row or fetch via userinfo.
     * @return array{registered: bool, email: string, responseBody: array<string, mixed>}
     */
    public function registerGcpAsDeveloper(int $shopId, ?string $developerEmail = null): array
    {
        $account = $this->accountRepository->findByShop($shopId);
        if (!$account) {
            throw new \RuntimeException('No account connected for this shop.');
        }

        $merchantId = (string) ($account['merchant_id'] ?? '');
        if ($merchantId === '') {
            throw new \RuntimeException('Merchant ID is not set. Save it first, then register.');
        }

        // Resolve the email in this order: explicit argument → DB → live userinfo lookup.
        $email = '';
        if ($developerEmail !== null) {
            $email = trim($developerEmail);
        }
        if ($email === '') {
            $email = (string) ($account['email'] ?? '');
        }
        if ($email === '') {
            try {
                $accessToken = $this->http->getAccessToken($shopId);
                $email = $this->fetchUserEmail($accessToken);
            } catch (\Throwable $e) {
                // Will surface via the validation error below.
            }
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException(
                'Developer email is required. Provide the Google account email you used to log in.'
            );
        }

        $response = [];
        $alreadyRegistered = false;

        try {
            $response = $this->http->post(
                $shopId,
                sprintf('/accounts/v1/accounts/%s/developerRegistration:registerGcp', $merchantId),
                ['developer_email' => $email]
            );
        } catch (MerchantApiException $e) {
            // Google returns an error message containing "already registered" when the GCP
            // project was previously linked. From our point of view that's success — the
            // registration we wanted is in place. Detect by message text since the API
            // doesn't expose a structured reason for this case.
            if (stripos($e->getMessage(), 'already registered') === false) {
                throw $e;
            }
            $alreadyRegistered = true;
            $response = ['alreadyRegistered' => true];
        }

        // Persist email + flag the account as registered so the UI can render the
        // success state without hitting Google again on every render.
        $updates = ['gcp_registered' => 1];
        if (empty($account['email'])) {
            $updates['email'] = $email;
        }

        // Now that the GCP project is registered, the account info endpoint should work.
        // Try to pull the friendly Merchant Center display name and cache it.
        if (empty($account['merchant_name'])) {
            try {
                $accountResponse = $this->http->get(
                    $shopId,
                    sprintf('/accounts/v1/accounts/%s', $merchantId)
                );
                $merchantName = (string) ($accountResponse['accountName'] ?? '');
                if ($merchantName !== '') {
                    $updates['merchant_name'] = $merchantName;
                }
            } catch (\Throwable $e) {
                // Friendly name lookup is best-effort — registration itself already succeeded.
            }
        }

        $this->accountRepository->upsert($shopId, $updates);

        return [
            'registered' => true,
            'alreadyRegistered' => $alreadyRegistered,
            'email' => $email,
            'merchantName' => $updates['merchant_name'] ?? ($account['merchant_name'] ?? ''),
            'responseBody' => $response,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a fresh OAuth2 helper for the authorization-code flow.
     */
    private function buildOAuth2(string $redirectUri): OAuth2
    {
        $clientId = (string) \Configuration::get('FEEDFORGE_GOOGLE_CLIENT_ID');
        $clientSecret = (string) \Configuration::get('FEEDFORGE_GOOGLE_CLIENT_SECRET');

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException(
                'Google OAuth client credentials are not configured. Please set them in Configuration.'
            );
        }

        return new OAuth2([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'authorizationUri' => self::AUTHORIZATION_URI,
            'tokenCredentialUri' => self::TOKEN_CREDENTIAL_URI,
            'redirectUri' => $redirectUri,
            'scope' => self::SCOPE,
        ]);
    }

    /**
     * Probe the Merchant API for the user's merchant accounts after first connect.
     *
     * @return array{merchantId: string|null, merchantName: string|null}
     */
    private function fetchAccountInfo(int $shopId): array
    {
        $result = ['merchantId' => null, 'merchantName' => null];

        try {
            // Endpoint: GET /accounts/v1/accounts (lists all accounts the user has access to)
            $response = $this->http->get($shopId, '/accounts/v1/accounts');

            foreach ($response['accounts'] ?? [] as $account) {
                $name = (string) ($account['name'] ?? '');
                if (preg_match('#^accounts/(\d+)$#', $name, $matches)) {
                    $result['merchantId'] = $matches[1];
                    $result['merchantName'] = (string) ($account['accountName'] ?? '');
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Not fatal — user can fill in the merchant ID manually if needed.
        }

        return $result;
    }

    /**
     * Fetch the user's email via Google's userinfo endpoint.
     */
    private function fetchUserEmail(string $accessToken): string
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return '';
        }

        $data = json_decode((string) $response, true);

        return is_array($data) && isset($data['email']) ? (string) $data['email'] : '';
    }

    /**
     * Best-effort token revocation against Google's revoke endpoint.
     */
    private function revokeToken(string $token): void
    {
        $ch = curl_init(self::REVOKE_URI);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['token' => $token]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
    }
}
