<?php

namespace App\Http\Middleware;

use App\Services\ShopifyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class ShopifyAuthenticate
{
    public function __construct(
        private ShopifyService $shopifyService
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $authorization = $request->header('Authorization');

        if (
            !$authorization ||
            !str_starts_with($authorization, 'Bearer ')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Shopify authorization token missing.',
            ], 401);
        }

        $token = substr($authorization, 7);

        try {
            /*
             * Decode token payload first so we can obtain
             * the Shopify shop from the `dest` claim.
             */
            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                throw new \Exception(
                    'Invalid Shopify token format.'
                );
            }

            $payload = json_decode(
                base64_decode(
                    strtr($parts[1], '-_', '+/')
                ),
                true
            );

            if (!is_array($payload)) {
                throw new \Exception(
                    'Invalid Shopify token payload.'
                );
            }

            /*
             * Validate expiration.
             */
            if (
                empty($payload['exp']) ||
                $payload['exp'] < time()
            ) {
                throw new \Exception(
                    'Shopify token has expired.'
                );
            }

            /*
             * Shopify ID token contains:
             *
             * dest:
             * https://store-name.myshopify.com
             */
            $destination = $payload['dest'] ?? null;

            if (!$destination) {
                throw new \Exception(
                    'Shopify shop information missing from token.'
                );
            }

            $shopDomain = parse_url(
                $destination,
                PHP_URL_HOST
            );

            if (!$shopDomain) {
                throw new \Exception(
                    'Invalid Shopify shop destination.'
                );
            }

            /*
             * Only allow Shopify shop domains.
             */
            if (
                !preg_match(
                    '/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/',
                    $shopDomain
                )
            ) {
                throw new \Exception(
                    'Invalid Shopify shop domain.'
                );
            }

            /*
             * Exchange the App Bridge ID token for
             * an offline Admin API access token.
             */
            $shop = $this->shopifyService->exchangeToken(
                $shopDomain,
                $token
            );

            /*
             * Make authenticated shop available
             * to controllers.
             */
            $request->attributes->set(
                'shop',
                $shop
            );

            $request->attributes->set(
                'shopify_claims',
                $payload
            );

            return $next($request);

        } catch (\Throwable $e) {

            Log::error(
                'Shopify authentication failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Shopify authentication failed.',
                'error' => $e->getMessage(),
            ], 401);
        }
    }
}