<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

class ShopifyService
{
    public function getValidAccessToken(Shop $shop): string
    {
        if (
            $shop->access_token &&
            $shop->access_token_expires_at &&
            now()->lt($shop->access_token_expires_at)
        ) {
            return $shop->access_token;
        }

        if ($shop->refresh_token) {
            return $this->refreshAccessToken($shop);
        }

        throw new \Exception(
            'Shopify access token is missing. Token exchange is required.'
        );
    }

    /**
     * Exchange Shopify App Bridge ID token for
     * an offline Admin API access token.
     */
    public function exchangeToken(
        string $shopDomain,
        string $idToken
    ): Shop {
        $response = Http::asForm()
            ->acceptJson()
            ->post(
                "https://{$shopDomain}/admin/oauth/access_token",
                [
                    'client_id' => env('SHOPIFY_API_KEY'),
                    'client_secret' => env('SHOPIFY_API_SECRET'),

                    'grant_type' =>
                        'urn:ietf:params:oauth:grant-type:token-exchange',

                    'subject_token' => $idToken,

                    'subject_token_type' =>
                        'urn:shopify:params:oauth:token-type:id_token',

                    'requested_token_type' =>
                        'urn:shopify:params:oauth:token-type:offline-access-token',
                ]
            );

        if (!$response->successful()) {
            throw new \Exception(
                'Shopify token exchange failed: ' .
                $response->body()
            );
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new \Exception(
                'Shopify did not return an access token.'
            );
        }

        $shop = Shop::updateOrCreate(
            [
                'shop_domain' => $shopDomain,
            ],
            [
                'access_token' => $data['access_token'],

                /*
                 * Token exchange does not use the old
                 * authorization-code refresh token flow.
                 */
                'refresh_token' => null,

                'scope' => $data['scope'] ?? null,

                'access_token_expires_at' =>
                    isset($data['expires_in'])
                        ? now()->addSeconds($data['expires_in'])
                        : null,
            ]
        );

        return $shop;
    }

    /**
     * Refresh an expiring token from the old OAuth flow.
     *
     * Kept temporarily for existing installations.
     */
    private function refreshAccessToken(Shop $shop): string
    {
        if (!$shop->refresh_token) {
            throw new \Exception(
                'Shopify refresh token is missing.'
            );
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post(
                "https://{$shop->shop_domain}/admin/oauth/access_token",
                [
                    'client_id' => env('SHOPIFY_API_KEY'),
                    'client_secret' => env('SHOPIFY_API_SECRET'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $shop->refresh_token,
                ]
            );

        if (!$response->successful()) {
            throw new \Exception(
                'Shopify token refresh failed: ' .
                $response->body()
            );
        }

        $data = $response->json();

        if (empty($data['access_token'])) {
            throw new \Exception(
                'Shopify refresh did not return an access token.'
            );
        }

        $shop->update([
            'access_token' => $data['access_token'],

            'refresh_token' =>
                $data['refresh_token'] ?? $shop->refresh_token,

            'scope' =>
                $data['scope'] ?? $shop->scope,

            'access_token_expires_at' =>
                isset($data['expires_in'])
                    ? now()->addSeconds($data['expires_in'])
                    : null,
        ]);

        return $data['access_token'];
    }

    /**
     * Register products/update webhook.
     */
    public function registerProductUpdateWebhook(
        Shop $shop
    ): array {
        $accessToken =
            $this->getValidAccessToken($shop);

        $webhookUrl =
            rtrim(env('SHOPIFY_APP_URL'), '/') .
            '/webhooks/products';

        $query = <<<'GRAPHQL'
query {
    webhookSubscriptions(first: 50) {
        nodes {
            id
            topic
            uri
        }
    }
}
GRAPHQL;

        $checkResponse = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->post(
            "https://{$shop->shop_domain}/admin/api/2026-07/graphql.json",
            [
                'query' => $query,
            ]
        );

        if (!$checkResponse->successful()) {
            throw new \Exception(
                'Failed to check Shopify webhooks: ' .
                $checkResponse->body()
            );
        }

        $checkData = $checkResponse->json();

        if (!empty($checkData['errors'])) {
            throw new \Exception(
                'Shopify webhook query failed: ' .
                json_encode($checkData['errors'])
            );
        }

        $existingWebhooks =
            $checkData['data']['webhookSubscriptions']['nodes'] ?? [];

        foreach ($existingWebhooks as $webhook) {
            if (
                $webhook['topic'] === 'PRODUCTS_UPDATE' &&
                $webhook['uri'] === $webhookUrl
            ) {
                return [
                    'success' => true,
                    'created' => false,
                    'message' =>
                        'Product update webhook already exists.',
                    'webhook_id' => $webhook['id'],
                    'uri' => $webhook['uri'],
                ];
            }
        }

        $mutation = <<<'GRAPHQL'
mutation webhookSubscriptionCreate(
    $topic: WebhookSubscriptionTopic!,
    $webhookSubscription: WebhookSubscriptionInput!
) {
    webhookSubscriptionCreate(
        topic: $topic,
        webhookSubscription: $webhookSubscription
    ) {
        webhookSubscription {
            id
            topic
            uri
        }

        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->post(
            "https://{$shop->shop_domain}/admin/api/2026-07/graphql.json",
            [
                'query' => $mutation,

                'variables' => [
                    'topic' => 'PRODUCTS_UPDATE',

                    'webhookSubscription' => [
                        'uri' => $webhookUrl,
                    ],
                ],
            ]
        );

        if (!$response->successful()) {
            throw new \Exception(
                'Failed to create Shopify webhook: ' .
                $response->body()
            );
        }

        $data = $response->json();

        if (!empty($data['errors'])) {
            throw new \Exception(
                'Shopify webhook creation failed: ' .
                json_encode($data['errors'])
            );
        }

        $result =
            $data['data']['webhookSubscriptionCreate'] ?? null;

        if (!$result) {
            throw new \Exception(
                'Invalid Shopify webhook creation response.'
            );
        }

        if (!empty($result['userErrors'])) {
            throw new \Exception(
                'Shopify webhook user errors: ' .
                json_encode($result['userErrors'])
            );
        }

        return [
            'success' => true,
            'created' => true,
            'message' =>
                'Product update webhook created successfully.',
            'webhook_id' =>
                $result['webhookSubscription']['id'] ?? null,
            'uri' =>
                $result['webhookSubscription']['uri']
                ?? $webhookUrl,
        ];
    }
}