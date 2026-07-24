<?php

declare(strict_types=1);

namespace FeedForge\Controller\Admin;

use FeedForge\Service\GoogleApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles Google OAuth 2.0 authentication flow.
 */
class OAuthController extends BaseController
{

    public function __construct(
        private readonly GoogleApiClient $googleApiClient,
    ) {
    }

    /**
     * Redirect to Configuration page via proper admin URL (includes /admin-dev/ and _token).
     */
    private function redirectToConfig(): RedirectResponse
    {
        $adminDir = basename(_PS_ADMIN_DIR_);
        $token = \Tools::getAdminTokenLite('AdminFeedForgeConfig');

        return $this->redirect('/' . $adminDir . '/modules/feedforge/config?_token=' . $token);
    }

    /**
     * Initiates OAuth flow - redirects to Google consent screen.
     */
    public function connectAction(Request $request): RedirectResponse
    {
        $clientId = \Configuration::get('FEEDFORGE_GOOGLE_CLIENT_ID');
        $clientSecret = \Configuration::get('FEEDFORGE_GOOGLE_CLIENT_SECRET');

        if (empty($clientId) || empty($clientSecret)) {
            $this->addFlash(
                'error',
                $this->trans('Fill in Google Client ID and Client Secret in configuration before connecting.', 'Modules.Feedforge.Admin')
            );

            return $this->redirectToConfig();
        }

        // CSRF protection via state parameter
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set('feedforge_oauth_state', $state);

        $redirectUri = $this->buildOAuthRedirectUri($request);

        try {
            $authUrl = $this->googleApiClient->getAuthUrl($redirectUri, $state);

            return $this->redirect($authUrl);
        } catch (\Exception $e) {
            $this->addFlash('error', $this->trans('Failed to initialize Google connection: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]));

            return $this->redirectToConfig();
        }
    }

    /**
     * OAuth callback - Google redirects here after user consent.
     */
    public function callbackAction(Request $request): RedirectResponse
    {
        // Check for errors from Google
        $error = $request->query->get('error');
        if ($error) {
            $this->addFlash('error', $this->trans('Google denied access: %error%', 'Modules.Feedforge.Admin', ['%error%' => $error]));

            return $this->redirectToConfig();
        }

        // Validate state parameter (CSRF protection)
        $state = $request->query->get('state', '');
        $sessionState = $request->getSession()->get('feedforge_oauth_state', '');
        $request->getSession()->remove('feedforge_oauth_state');

        if (empty($state) || !hash_equals($sessionState, $state)) {
            $this->addFlash('error', $this->trans('Invalid state parameter. Try connecting again.', 'Modules.Feedforge.Admin'));

            return $this->redirectToConfig();
        }

        // Exchange authorization code for tokens
        $code = $request->query->get('code', '');
        if (empty($code)) {
            $this->addFlash('error', $this->trans('No authorization code from Google.', 'Modules.Feedforge.Admin'));

            return $this->redirectToConfig();
        }

        try {
            $shopId = (int) $this->getContext()->shop->id;
            $redirectUri = $this->buildOAuthRedirectUri($request);

            $accountInfo = $this->googleApiClient->handleCallback($code, $redirectUri, $shopId);

            $message = $this->trans('Connected to Google Merchant Center', 'Modules.Feedforge.Admin');
            if (!empty($accountInfo['email'])) {
                $message .= ' (' . $accountInfo['email'] . ')';
            }
            if (!empty($accountInfo['merchantId'])) {
                $message .= ' — Merchant ID: ' . $accountInfo['merchantId'];
            }

            $this->addFlash('success', $message);
        } catch (\Exception $e) {
            $this->addFlash('error', $this->trans('Error connecting to Google: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToConfig();
    }

    /**
     * Build the OAuth redirect URI with the admin directory prefix.
     * Symfony's generateUrl() omits /admin-dev, but Google requires an exact match.
     */
    private function buildOAuthRedirectUri(Request $request): string
    {
        return $request->getSchemeAndHttpHost()
            . $request->getBaseUrl()
            . '/modules/feedforge/oauth/callback';
    }

    /**
     * Disconnect - remove OAuth tokens and GMC account link.
     */
    public function disconnectAction(Request $request): JsonResponse
    {
        try {
            $shopId = (int) $this->getContext()->shop->id;

            $this->googleApiClient->disconnect($shopId);

            return $this->json([
                'success' => true,
                'message' => $this->trans('Disconnected from Google Merchant Center', 'Modules.Feedforge.Admin'),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $this->trans('Error disconnecting: %error%', 'Modules.Feedforge.Admin', ['%error%' => $e->getMessage()]),
            ], 500);
        }
    }
}
