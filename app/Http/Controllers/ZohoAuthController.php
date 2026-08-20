<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Models\ZohoOauthState;
use App\Services\ZohoDatacenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ZohoAuthController extends Controller {
    /**
     * Protected endpoint to initiate Zoho Books OAuth flow from authenticated Shopify app context.
     * Always uses the global Multi-DC authorization entry point (accounts.zoho.com).
     */
    public function initiate(Request $request) {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'error' => 'No Shopify shop installed.',
            ], 401);
        }

        $host = $request->input('host') ?: $request->query('host');

        if (!$host || !$this->isValidShopifyHost($host)) {
            return response()->json([
                'error' => 'Invalid or missing Shopify host parameter.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Cryptographically Secure OAuth State & Store SHA-256 Hash
        |--------------------------------------------------------------------------
        */

        $rawState = bin2hex(random_bytes(32));
        $stateHash = hash('sha256', $rawState);

        ZohoOauthState::create([
            'state' => $stateHash,
            'shop_id' => $shop->id,
            'host' => $host,
            'expires_at' => now()->addMinutes(15),
            'consumed_at' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Global OAuth Initiation Entry Point (accounts.zoho.com)
        |--------------------------------------------------------------------------
        */

        $clientId = config('services.zoho.client_id') ?: env('ZOHO_CLIENT_ID');
        $redirectUri = config('services.zoho.redirect_uri') ?: env('ZOHO_REDIRECT_URI');

        $scopes = config('services.zoho.scopes', [
            'ZohoBooks.settings.READ',
            'ZohoBooks.settings.CREATE',
            'ZohoBooks.settings.UPDATE',
            'ZohoBooks.settings.DELETE',
            'ZohoBooks.fullaccess.READ',
            'ZohoBooks.fullaccess.CREATE',
            'ZohoBooks.fullaccess.UPDATE',
            'ZohoBooks.fullaccess.DELETE',
            'ZohoBooks.contacts.READ',
            'ZohoBooks.contacts.CREATE',
            'ZohoBooks.contacts.UPDATE',
            'ZohoBooks.salesorders.READ',
            'ZohoBooks.salesorders.CREATE',
            'ZohoBooks.salesorders.UPDATE',
            'ZohoBooks.invoices.READ',
            'ZohoBooks.invoices.CREATE',
            'ZohoBooks.invoices.UPDATE',
            'ZohoBooks.customerpayments.READ',
            'ZohoBooks.customerpayments.CREATE',
            'ZohoBooks.creditnotes.READ',
            'ZohoBooks.creditnotes.CREATE',
            'ZohoBooks.creditnotes.UPDATE',
            'ZohoBooks.creditnotes.DELETE',
            'ZohoInventory.settings.READ',
            'ZohoInventory.items.READ',
            'ZohoInventory.items.CREATE',
            'ZohoInventory.items.UPDATE',
            'ZohoInventory.items.DELETE',
            'ZohoInventory.inventoryadjustments.READ',
            'ZohoInventory.inventoryadjustments.CREATE',
            'ZohoInventory.inventoryadjustments.UPDATE',
            'ERP.settings.READ',
            'ERP.inventoryadjustments.READ',
            'ERP.inventoryadjustments.CREATE',
            'ERP.inventoryadjustments.UPDATE',
        ]);

        $queryParams = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'access_type' => 'offline',
            'scope' => implode(',', $scopes),
            'redirect_uri' => $redirectUri,
            'state' => $rawState,
        ];

        if ($request->has('prompt') && trim((string) $request->input('prompt')) !== '') {
            $queryParams['prompt'] = trim((string) $request->input('prompt'));
        } elseif ($request->boolean('reconsent')) {
            $queryParams['prompt'] = 'consent';
        }

        $loginHint = $request->input('login_hint') ?: $request->query('login_hint');
        if (!empty($loginHint)) {
            $queryParams['login_hint'] = trim((string) $loginHint);
        }

        $params = http_build_query($queryParams);

        $accountsBaseUrl = ZohoDatacenter::getInitiationAccountsUrl($shop);
        $url = $accountsBaseUrl . '/oauth/v2/auth?' . $params;

        return response()->json([
            'success' => true,
            'redirect_url' => $url,
        ]);
    }

    /**
     * Handle public Zoho OAuth callback.
     */
    public function callback(Request $request)
    {
        $errorCode = $request->query('error');
        $code = $request->query('code');
        $rawState = $request->query('state');
        $wantsJson = $request->wantsJson();

        // 1. Resolve state record if rawState is present
        $stateRecord = null;
        $shopModel = null;
        $host = null;
        if ($rawState && is_string($rawState)) {
            $stateHash = hash('sha256', $rawState);
            $stateRecord = ZohoOauthState::where('state', $stateHash)->first();
            if ($stateRecord) {
                $shopModel = Shop::find($stateRecord->shop_id);
                $host = $stateRecord->host;
            }
        }

        // Check if shop currently has an active connection
        $existingConnection = $shopModel ? ZohoConnection::where('shop_id', $shopModel->id)->first() : null;
        $isReauth = $existingConnection && (bool) $existingConnection->is_active;

        $redirectUrl = $this->resolveRedirectUrl($shopModel, $host);

        // 2. Handle explicit OAuth error or rejection (e.g. error=access_denied)
        if ($errorCode) {
            if ($stateRecord && $stateRecord->consumed_at === null) {
                $stateRecord->update(['consumed_at' => now()]);
            }

            $message = ($errorCode === 'access_denied' || $errorCode === 'user_cancelled')
                ? ($isReauth
                    ? 'Zoho reauthorization was cancelled. Your existing connection is still active.'
                    : 'Zoho authorization was cancelled.')
                : 'Unable to connect to Zoho Books. Please try again.';

            if ($wantsJson) {
                return response()->json(['error' => $message], 400);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: $message,
                redirectUrl: $redirectUrl,
                isSuccess: false,
                isReauthorization: $isReauth
            );
        }

        // 3. Missing code or state
        if (!$code || !$rawState) {
            if ($wantsJson) {
                return response()->json(['error' => 'Authorization code or state missing.'], 400);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: 'Unable to connect to Zoho Books. Please try again.',
                redirectUrl: $redirectUrl,
                isSuccess: false,
                isReauthorization: $isReauth
            );
        }

        // 4. Validate state record existence
        if (!$stateRecord) {
            if ($wantsJson) {
                return response()->json(['error' => 'Invalid Zoho OAuth state.'], 403);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: 'Unable to connect to Zoho Books. Please try again.',
                redirectUrl: $redirectUrl,
                isSuccess: false,
                isReauthorization: $isReauth
            );
        }

        if ($stateRecord->consumed_at !== null) {
            if ($wantsJson) {
                return response()->json(['error' => 'Zoho OAuth state has already been used.'], 403);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: 'Unable to connect to Zoho Books. Please try again.',
                redirectUrl: $redirectUrl,
                isSuccess: false,
                isReauthorization: $isReauth
            );
        }

        if (now()->gte($stateRecord->expires_at)) {
            if ($wantsJson) {
                return response()->json(['error' => 'Zoho OAuth state has expired.'], 403);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: 'Unable to connect to Zoho Books. Please try again.',
                redirectUrl: $redirectUrl,
                isSuccess: false,
                isReauthorization: $isReauth
            );
        }

        // Consume state immediately
        $stateRecord->update(['consumed_at' => now()]);

        if (!$shopModel) {
            if ($wantsJson) {
                return response()->json(['error' => 'Associated Shopify shop not found.'], 404);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: 'Unable to connect to Zoho Books. Please try again.',
                redirectUrl: null,
                isSuccess: false,
                isReauthorization: false
            );
        }

        if (!$this->isValidShopifyHost((string) $host)) {
            if ($wantsJson) {
                return response()->json(['error' => 'Invalid Shopify host destination.'], 400);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: 'Unable to connect to Zoho Books. Please try again.',
                redirectUrl: null,
                isSuccess: false,
                isReauthorization: $isReauth
            );
        }

        // 5. Resolve accounts server
        $accountsServerParam = $request->query('accounts-server');
        $validatedAccountsServer = null;

        if ($accountsServerParam !== null && trim($accountsServerParam) !== '') {
            $validatedAccountsServer = ZohoDatacenter::validateAccountsUrl($accountsServerParam);
            if (!$validatedAccountsServer) {
                if ($wantsJson) {
                    return response()->json(['error' => 'Invalid or untrusted Zoho accounts-server parameter.'], 400);
                }

                return $this->renderCallbackHtml(
                    type: 'ZOHO_AUTH_CANCELLED',
                    message: 'Unable to connect to Zoho Books. Please try again.',
                    redirectUrl: $redirectUrl,
                    isSuccess: false,
                    isReauthorization: $isReauth
                );
            }
            $tokenExchangeAccountsUrl = $validatedAccountsServer;
        } else {
            $tokenExchangeAccountsUrl = ZohoDatacenter::resolveAccountsUrl(
                null,
                $request->query('location')
            );
        }

        // 6. Exchange code for tokens
        $clientId = config('services.zoho.client_id') ?: env('ZOHO_CLIENT_ID');
        $clientSecret = config('services.zoho.client_secret') ?: env('ZOHO_CLIENT_SECRET');
        $redirectUri = config('services.zoho.redirect_uri') ?: env('ZOHO_REDIRECT_URI');

        try {
            $response = Http::asForm()->post(
                $tokenExchangeAccountsUrl . '/oauth/v2/token',
                [
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ]
            );

            if (!$response->successful()) {
                Log::warning("Zoho OAuth token exchange failed for shop {$shopModel->id} (HTTP {$response->status()})");
                if ($wantsJson) {
                    return response()->json(['error' => 'Zoho token exchange failed.'], 500);
                }
                return $this->renderCallbackHtml(
                    type: 'ZOHO_AUTH_CANCELLED',
                    message: 'Unable to connect to Zoho Books. Please try again.',
                    redirectUrl: $redirectUrl,
                    isSuccess: false,
                    isReauthorization: $isReauth
                );
            }

            $tokenData = $response->json();
            $accessToken = $tokenData['access_token'] ?? null;
            $refreshToken = $tokenData['refresh_token'] ?? null;

            if (!$accessToken || !$refreshToken) {
                Log::warning("Zoho OAuth response missing tokens for shop {$shopModel->id}");
                if ($wantsJson) {
                    return response()->json(['error' => 'Zoho did not return required tokens.'], 500);
                }
                return $this->renderCallbackHtml(
                    type: 'ZOHO_AUTH_CANCELLED',
                    message: 'Unable to connect to Zoho Books. Please try again.',
                    redirectUrl: $redirectUrl,
                    isSuccess: false,
                    isReauthorization: $isReauth
                );
            }

            // Resolve API URL & domain
            $rawApiDomain = $tokenData['api_domain'] ?? null;
            if ($rawApiDomain !== null && trim($rawApiDomain) !== '') {
                $apiUrl = ZohoDatacenter::validateApiUrl($rawApiDomain);
                if (!$apiUrl) {
                    if ($wantsJson) {
                        return response()->json(['error' => 'Invalid or untrusted Zoho API domain in token response.'], 400);
                    }
                    return $this->renderCallbackHtml(
                        type: 'ZOHO_AUTH_CANCELLED',
                        message: 'Unable to connect to Zoho Books. Please try again.',
                        redirectUrl: $redirectUrl,
                        isSuccess: false,
                        isReauthorization: $isReauth
                    );
                }
            } else {
                $apiUrl = ZohoDatacenter::validateApiUrl(config('services.zoho.api_url') ?: env('ZOHO_API_URL')) ?? 'https://www.zohoapis.com';
            }

            $accountsUrl = !empty($validatedAccountsServer)
                ? $validatedAccountsServer
                : ZohoDatacenter::getAccountsUrlForApiUrl($apiUrl);
            $apiDomainHost = parse_url($apiUrl, PHP_URL_HOST);

            // Fetch Organizations
            $orgResponse = Http::withHeaders([
                'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
                'Accept' => 'application/json',
            ])->get($apiUrl . '/books/v3/organizations');

            if (!$orgResponse->successful()) {
                Log::warning("Failed to fetch Zoho organizations for shop {$shopModel->id}");
                if ($wantsJson) {
                    return response()->json(['error' => 'Failed to fetch Zoho organizations.'], 500);
                }
                return $this->renderCallbackHtml(
                    type: 'ZOHO_AUTH_CANCELLED',
                    message: 'Unable to connect to Zoho Books. Please try again.',
                    redirectUrl: $redirectUrl,
                    isSuccess: false,
                    isReauthorization: $isReauth
                );
            }

            $organizations = $orgResponse->json('organizations', []);
            if (empty($organizations)) {
                if ($wantsJson) {
                    return response()->json(['error' => 'No Zoho Books organization found.'], 404);
                }
                return $this->renderCallbackHtml(
                    type: 'ZOHO_AUTH_CANCELLED',
                    message: 'Unable to connect to Zoho Books. Please try again.',
                    redirectUrl: $redirectUrl,
                    isSuccess: false,
                    isReauthorization: $isReauth
                );
            }

            $organization = collect($organizations)->firstWhere('is_default_org', true) ?? $organizations[0];
            $organizationId = $organization['organization_id'] ?? null;

            if (!$organizationId) {
                if ($wantsJson) {
                    return response()->json(['error' => 'Zoho organization ID not found.'], 500);
                }
                return $this->renderCallbackHtml(
                    type: 'ZOHO_AUTH_CANCELLED',
                    message: 'Unable to connect to Zoho Books. Please try again.',
                    redirectUrl: $redirectUrl,
                    isSuccess: false,
                    isReauthorization: $isReauth
                );
            }

            // Save / Update Connection
            ZohoConnection::updateOrCreate(
                ['shop_id' => $shopModel->id],
                [
                    'is_active' => true,
                    'organization_id' => $organizationId,
                    'organization_name' => $organization['name'] ?? $organization['organization_name'] ?? null,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'accounts_url' => $accountsUrl,
                    'api_url' => $apiUrl,
                    'api_domain' => $apiDomainHost,
                    'data_center' => strtolower($apiDomainHost ?? ''),
                    'scope' => $tokenData['scope'] ?? implode(',', config('services.zoho.scopes', [])),
                    'connected_at' => now(),
                    'disconnected_at' => null,
                    'expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                ]
            );

            if ($wantsJson) {
                return response()->json(['success' => true, 'message' => 'Zoho Books connected successfully.']);
            }

            return $this->renderCallbackHtml(
                type: 'ZOHO_CONNECTED_SUCCESS',
                message: 'Zoho Books connected successfully.',
                redirectUrl: $redirectUrl,
                isSuccess: true,
                isReauthorization: $isReauth
            );
        } catch (\Throwable $ex) {
            Log::error("Zoho OAuth callback exception for shop {$shopModel->id}: " . $ex->getMessage());
            if ($wantsJson) {
                return response()->json(['error' => 'Unable to connect to Zoho Books. Please try again.'], 500);
            }
            return $this->renderCallbackHtml(
                type: 'ZOHO_AUTH_CANCELLED',
                message: 'Unable to connect to Zoho Books. Please try again.',
                redirectUrl: $redirectUrl,
                isSuccess: false,
                isReauthorization: $isReauth
            );
        }
    }

    /**
     * Render standalone HTML response for OAuth popup auto-close & opener notification.
     */
    private function renderCallbackHtml(
        string $type,
        string $message,
        ?string $redirectUrl = null,
        bool $isSuccess = true,
        bool $isReauthorization = false
    ) {
        $icon = $isSuccess ? '✓' : '⚠️';
        $iconColor = $isSuccess ? '#108043' : '#de3618';
        $title = $isSuccess ? 'Connected to Zoho Books' : ($isReauthorization ? 'Reauthorization Cancelled' : 'Authorization Cancelled');

        $payload = json_encode([
            'type' => $type,
            'message' => $message,
            'success' => $isSuccess,
            'isReauthorization' => $isReauthorization,
        ]);

        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeRedirectUrl = $redirectUrl ? json_encode($redirectUrl) : 'null';

        $html = '<!DOCTYPE html>' .
            '<html><head><title>' . $safeTitle . '</title></head>' .
            '<body style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background-color: #f6f6f7; color: #202223;">' .
            '<div style="text-align: center; background: #ffffff; padding: 36px 48px; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); max-width: 400px;">' .
            '<div style="font-size: 48px; color: ' . $iconColor . '; margin-bottom: 12px;">' . $icon . '</div>' .
            '<h2 style="color: #1a1d20; margin: 0 0 8px 0; font-size: 20px;">' . $safeTitle . '</h2>' .
            '<p style="color: #616a75; font-size: 14px; margin: 0;">' . $safeMessage . '</p>' .
            '</div>' .
            '<script>' .
            'const payload = ' . $payload . ';' .
            'if (window.opener) {' .
            '  try { window.opener.postMessage(payload, "*"); } catch(e) {}' .
            '  setTimeout(function() { window.close(); }, ' . ($isSuccess ? '500' : '1500') . ');' .
            '} else if (' . $safeRedirectUrl . ') {' .
            '  setTimeout(function() { window.location.href = ' . $safeRedirectUrl . '; }, 1500);' .
            '}' .
            '</script>' .
            '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    /**
     * Resolve standalone redirect URL back to Shopify app dashboard.
     */
    private function resolveRedirectUrl(?Shop $shopModel, ?string $host): ?string
    {
        if (!$shopModel || !$host || !$this->isValidShopifyHost($host)) {
            return null;
        }

        $decodedHost = base64_decode(strtr($host, '-_', '+/'));
        $adminUrl = 'https://' . $decodedHost . '/apps/zoho-books-integration-20';
        $query = http_build_query([
            'shop' => $shopModel->shop_domain,
            'host' => $host,
        ]);

        return $adminUrl . '?' . $query;
    }

    /**
     * Validate base64-encoded Shopify host string.
     */
    private function isValidShopifyHost(string $host): bool
    {
        if (trim($host) === '') {
            return false;
        }

        $decoded = base64_decode(strtr($host, '-_', '+/'), true);

        if ($decoded === false || trim($decoded) === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(admin\.shopify\.com\/store\/[a-zA-Z0-9\-]+|[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com(\/admin)?)$/',
            $decoded
        );
    }
}
