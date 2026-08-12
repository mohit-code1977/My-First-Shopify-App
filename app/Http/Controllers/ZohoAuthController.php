<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ZohoConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ZohoAuthController extends Controller
{
    /**
     * Start Zoho Books OAuth flow.
     */
    public function connect(Request $request)
    {
        $shop = $request->query('shop');
        $host = $request->query('host');

        if (!$shop || !$host) {
            return response()->json([
                'error' => 'Missing Shopify shop context.',
            ], 400);
        }

        // Make sure this Shopify store actually exists.
        $shopModel = Shop::where('shop_domain', $shop)->first();

        if (!$shopModel) {
            return response()->json([
                'error' => 'No Shopify shop installed.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate OAuth state
        |--------------------------------------------------------------------------
        */

        $state = Str::random(40);

        session([
            'zoho_oauth_state' => $state,
            'zoho_oauth_shop' => $shop,
            'zoho_oauth_host' => $host,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Build Zoho OAuth URL
        |--------------------------------------------------------------------------
        */

        $params = http_build_query([
            'client_id' => env('ZOHO_CLIENT_ID'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',

            'scope' => implode(',', [
                'ZohoBooks.settings.READ',
                'ZohoBooks.settings.CREATE',
                'ZohoBooks.settings.UPDATE',
            ]),

            'redirect_uri' => env('ZOHO_REDIRECT_URI'),
            'state' => $state,
        ]);

        $url = env('ZOHO_ACCOUNTS_URL')
            . '/oauth/v2/auth?'
            . $params;

        return redirect()->away($url);
    }

    /**
     * Handle Zoho OAuth callback.
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state');

        /*
        |--------------------------------------------------------------------------
        | Validate OAuth response
        |--------------------------------------------------------------------------
        */

        if (!$code || !$state) {
            return response()->json([
                'error' => 'Authorization code or state missing.',
            ], 400);
        }

        $savedState = session('zoho_oauth_state');

        if (
            !$savedState ||
            !hash_equals($savedState, $state)
        ) {
            return response()->json([
                'error' => 'Invalid Zoho OAuth state.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Recover Shopify context
        |--------------------------------------------------------------------------
        */

        $shop = session('zoho_oauth_shop');
        $host = session('zoho_oauth_host');

        if (!$shop || !$host) {
            return response()->json([
                'error' => 'Shopify context missing.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | Exchange authorization code for Zoho tokens
        |--------------------------------------------------------------------------
        */

        $response = Http::asForm()->post(
            env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token',
            [
                'code' => $code,
                'client_id' => env('ZOHO_CLIENT_ID'),
                'client_secret' => env('ZOHO_CLIENT_SECRET'),
                'redirect_uri' => env('ZOHO_REDIRECT_URI'),
                'grant_type' => 'authorization_code',
            ]
        );

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Zoho token exchange failed.',
                'response' => $response->json(),
            ], 500);
        }

        $tokenData = $response->json();

        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;

        if (!$accessToken) {
            return response()->json([
                'error' => 'Zoho did not return an access token.',
            ], 500);
        }

        if (!$refreshToken) {
            return response()->json([
                'error' => 'Zoho did not return a refresh token.',
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Zoho Books organizations
        |--------------------------------------------------------------------------
        */

        $organizationResponse = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
            'Accept' => 'application/json',
        ])->get(
            env('ZOHO_API_URL') . '/books/v3/organizations'
        );

        if (!$organizationResponse->successful()) {
            return response()->json([
                'error' => 'Failed to fetch Zoho organizations.',
                'response' => $organizationResponse->json(),
            ], 500);
        }

        $organizations = $organizationResponse->json(
            'organizations',
            []
        );

        if (empty($organizations)) {
            return response()->json([
                'error' => 'No Zoho Books organization found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Select default organization
        |--------------------------------------------------------------------------
        */

        $organization =
            collect($organizations)->firstWhere(
                'is_default_org',
                true
            )
            ?? $organizations[0];

        $organizationId =
            $organization['organization_id'] ?? null;

        if (!$organizationId) {
            return response()->json([
                'error' => 'Zoho organization ID not found.',
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Shopify shop
        |--------------------------------------------------------------------------
        */

        $shopModel = Shop::where(
            'shop_domain',
            $shop
        )->first();

        if (!$shopModel) {
            return response()->json([
                'error' => 'No Shopify shop installed.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Save / update Zoho connection
        |--------------------------------------------------------------------------
        */

        ZohoConnection::updateOrCreate(
            [
                'shop_id' => $shopModel->id,
            ],
            [
                'organization_id' => $organizationId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds(
                    $tokenData['expires_in'] ?? 3600
                ),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Clear OAuth session
        |--------------------------------------------------------------------------
        */

        session()->forget([
            'zoho_oauth_state',
            'zoho_oauth_shop',
            'zoho_oauth_host',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Return to Shopify embedded app ROOT
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Do NOT redirect to:
        |
        | /apps/zoho/settings
        |
        | Shopify Admin's embedded-app root should be opened first.
        | The app's own <s-app-nav> handles /zoho/settings internally.
        |
        */

        $decodedHost = base64_decode(
            strtr($host, '-_', '+/')
        );

        if (!$decodedHost) {
            return response()->json([
                'error' => 'Invalid Shopify host.',
            ], 400);
        }

        $adminUrl = 'https://' . $decodedHost . '/apps/' . config('shopify.api_key');

        $query = http_build_query([
            'shop' => $shop,
            'host' => $host,
        ]);

        return redirect()->away($adminUrl . '?' . $query);
    }
}
