<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    public function getValidAccessToken(Shop $shop): string
    {
        if ($shop->access_token) {
            if (!$shop->access_token_expires_at || now()->lt($shop->access_token_expires_at)) {
                return $shop->access_token;
            }
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
                    'urn:ietf:params:oauth:token-type:id_token',

                    'requested_token_type' =>
                    'urn:shopify:params:oauth:token-type:offline-access-token',

                    'expiring' => 1,
                ]
            );

        Log::info('Shopify token exchange completed', [
            'shop' => $shopDomain,
            'status' => $response->status(),
            'success' => $response->successful(),
        ]);

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

                'refresh_token' =>
                $data['refresh_token'] ?? null,

                'scope' =>
                $data['scope'] ?? null,

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
    public function registerProductUpdateWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'PRODUCTS_UPDATE',
            '/webhooks/products',
            'Product update'
        );
    }

    /**
     * Register inventory_levels/update webhook.
     */
    public function registerInventoryLevelUpdateWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'INVENTORY_LEVELS_UPDATE',
            '/webhooks/inventory-levels',
            'Inventory level update'
        );
    }

    /**
     * Generic helper to register a Shopify GraphQL webhook subscription.
     */
    protected function registerWebhookSubscription(
        Shop $shop,
        string $topic,
        string $path,
        string $label
    ): array {
        $accessToken = $this->getValidAccessToken($shop);
        $webhookUrl = rtrim(env('SHOPIFY_APP_URL'), '/') . $path;

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
                "Failed to check Shopify webhooks for {$label}: " . $checkResponse->body()
            );
        }

        $checkData = $checkResponse->json();

        if (!empty($checkData['errors'])) {
            throw new \Exception(
                "Shopify webhook query failed for {$label}: " . json_encode($checkData['errors'])
            );
        }

        $existingWebhooks = $checkData['data']['webhookSubscriptions']['nodes'] ?? [];

        foreach ($existingWebhooks as $webhook) {
            if ($webhook['topic'] === $topic && $webhook['uri'] === $webhookUrl) {
                return [
                    'success' => true,
                    'created' => false,
                    'message' => "{$label} webhook already exists.",
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
                    'topic' => $topic,
                    'webhookSubscription' => [
                        'uri' => $webhookUrl,
                    ],
                ],
            ]
        );

        if (!$response->successful()) {
            throw new \Exception(
                "Failed to create Shopify webhook for {$label}: " . $response->body()
            );
        }

        $data = $response->json();

        if (!empty($data['errors'])) {
            throw new \Exception(
                "Shopify webhook creation failed for {$label}: " . json_encode($data['errors'])
            );
        }

        $result = $data['data']['webhookSubscriptionCreate'] ?? null;

        if (!$result) {
            throw new \Exception(
                "Invalid Shopify webhook creation response for {$label}."
            );
        }

        if (!empty($result['userErrors'])) {
            throw new \Exception(
                "Shopify webhook user errors for {$label}: " . json_encode($result['userErrors'])
            );
        }

        return [
            'success' => true,
            'created' => true,
            'message' => "{$label} webhook created successfully.",
            'webhook_id' => $result['webhookSubscription']['id'] ?? null,
            'uri' => $result['webhookSubscription']['uri'] ?? $webhookUrl,
        ];
    }

    /**
     * Retrieve the primary/active Shopify location ID for inventory updates.
     */
    public function getPrimaryLocationId(Shop $shop): string
    {
        $accessToken = $this->getValidAccessToken($shop);

        $query = <<<'GRAPHQL'
query GetPrimaryLocation {
    locations(first: 10) {
        nodes {
            id
            name
            isPrimary
            isActive
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
                'query' => $query,
            ]
        );

        if (!$response->successful()) {
            throw new \Exception('Failed to fetch Shopify locations: ' . $response->body());
        }

        $data = $response->json();
        $locations = $data['data']['locations']['nodes'] ?? [];

        if (empty($locations)) {
            throw new \Exception('No active Shopify locations found.');
        }

        foreach ($locations as $loc) {
            if (!empty($loc['isPrimary']) && !empty($loc['isActive'])) {
                return (string) $loc['id'];
            }
        }

        return (string) $locations[0]['id'];
    }

    /**
     * Update inventory quantity on Shopify for a specific InventoryItem ID.
     * Clamps quantity to max(0, $quantity) to prevent overselling.
     */
    public function setInventoryQuantity(
        Shop $shop,
        string $inventoryItemId,
        int $quantity,
        ?string $locationId = null
    ): array {
        $accessToken = $this->getValidAccessToken($shop);

        $safeQuantity = max(0, $quantity);
        if ($safeQuantity !== $quantity) {
            Log::warning("setInventoryQuantity: Overselling prevented. Quantity {$quantity} clamped to 0 for item {$inventoryItemId}.");
        }

        $formattedInventoryItemId = str_starts_with($inventoryItemId, 'gid://')
            ? $inventoryItemId
            : "gid://shopify/InventoryItem/{$inventoryItemId}";

        $targetLocationId = $locationId ?? $this->getPrimaryLocationId($shop);
        $formattedLocationId = str_starts_with($targetLocationId, 'gid://')
            ? $targetLocationId
            : "gid://shopify/Location/{$targetLocationId}";

        $mutation = <<<'GRAPHQL'
mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
    inventorySetQuantities(input: $input) {
        inventoryAdjustmentGroup {
            id
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
                    'input' => [
                        'reason' => 'correction',
                        'name' => 'available',
                        'ignoreCompareQuantity' => true,
                        'quantities' => [
                            [
                                'inventoryItemId' => $formattedInventoryItemId,
                                'locationId' => $formattedLocationId,
                                'quantity' => $safeQuantity,
                            ],
                        ],
                    ],
                ],
            ]
        );

        if (!$response->successful()) {
            throw new \Exception('Shopify inventory set quantities API request failed: ' . $response->body());
        }

        $data = $response->json();

        if (!empty($data['errors'])) {
            throw new \Exception('Shopify GraphQL inventory update failed: ' . json_encode($data['errors']));
        }

        $result = $data['data']['inventorySetQuantities'] ?? [];
        if (!empty($result['userErrors'])) {
            throw new \Exception('Shopify inventory update user errors: ' . json_encode($result['userErrors']));
        }

        return [
            'success' => true,
            'shopify_inventory_item_id' => $formattedInventoryItemId,
            'location_id' => $formattedLocationId,
            'quantity' => $safeQuantity,
            'response' => $data,
        ];
    }

    /**
     * Fetch orders from Shopify Admin GraphQL API.
     */
    public function fetchOrders(Shop $shop, int $limit = 50): array
    {
        $token = $this->getValidAccessToken($shop);

        $query = <<<'GRAPHQL'
query fetchOrders($first: Int!) {
    orders(first: $first, sortKey: CREATED_AT, reverse: true) {
        nodes {
            id
            name
            createdAt
            currencyCode
            subtotalPriceSet { shopMoney { amount } }
            totalDiscountsSet { shopMoney { amount } }
            totalTaxSet { shopMoney { amount } }
            totalPriceSet { shopMoney { amount } }
            displayFinancialStatus
            displayFulfillmentStatus
            note
            customer {
                id
                firstName
                lastName
                email
                defaultAddress {
                    address1
                    city
                    province
                    zip
                    country
                }
            }
            lineItems(first: 50) {
                nodes {
                    id
                    title
                    quantity
                    originalUnitPriceSet { shopMoney { amount } }
                    variant {
                        id
                        sku
                    }
                }
            }
        }
    }
}
GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post("https://{$shop->shop_domain}/admin/api/2026-07/graphql.json", [
            'query' => $query,
            'variables' => ['first' => $limit],
        ]);

        if (!$response->successful()) {
            Log::error('Failed to fetch Shopify orders via GraphQL', [
                'shop' => $shop->shop_domain,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->status() === 403) {
                $shop->update(['scope' => null]);
            }

            throw new \Exception("Failed to fetch Shopify orders (HTTP {$response->status()}): {$response->body()}");
        }

        $data = $response->json();
        if (!empty($data['errors'])) {
            Log::error('GraphQL errors fetching orders', ['errors' => $data['errors']]);
            throw new \Exception('GraphQL errors fetching orders: ' . json_encode($data['errors']));
        }

        $nodes = $data['data']['orders']['nodes'] ?? [];
        $orders = [];

        foreach ($nodes as $node) {
            $numericId = preg_replace('/[^0-9]/', '', $node['id'] ?? '');
            $custNode = $node['customer'] ?? null;
            $customer = null;
            if ($custNode) {
                $custNumericId = preg_replace('/[^0-9]/', '', $custNode['id'] ?? '');
                $customer = [
                    'id' => $custNumericId,
                    'first_name' => $custNode['firstName'] ?? '',
                    'last_name' => $custNode['lastName'] ?? '',
                    'email' => $custNode['email'] ?? '',
                    'phone' => $custNode['phone'] ?? null,
                    'default_address' => $custNode['defaultAddress'] ?? [],
                ];
            }

            $lineItems = [];
            foreach ($node['lineItems']['nodes'] ?? [] as $li) {
                $lineItems[] = [
                    'id' => preg_replace('/[^0-9]/', '', $li['id'] ?? ''),
                    'title' => $li['title'] ?? '',
                    'quantity' => $li['quantity'] ?? 1,
                    'price' => $li['originalUnitPriceSet']['shopMoney']['amount'] ?? '0.00',
                    'sku' => $li['variant']['sku'] ?? '',
                    'variant_id' => !empty($li['variant']['id']) ? preg_replace('/[^0-9]/', '', $li['variant']['id']) : null,
                ];
            }

            $orders[] = [
                'id' => $numericId,
                'name' => $node['name'] ?? "#{$numericId}",
                'order_number' => $numericId,
                'created_at' => $node['createdAt'] ?? null,
                'currency' => $node['currencyCode'] ?? 'USD',
                'subtotal_price' => $node['subtotalPriceSet']['shopMoney']['amount'] ?? '0.00',
                'total_discounts' => $node['totalDiscountsSet']['shopMoney']['amount'] ?? '0.00',
                'total_tax' => $node['totalTaxSet']['shopMoney']['amount'] ?? '0.00',
                'total_price' => $node['totalPriceSet']['shopMoney']['amount'] ?? '0.00',
                'financial_status' => strtolower($node['displayFinancialStatus'] ?? 'pending'),
                'fulfillment_status' => strtolower($node['displayFulfillmentStatus'] ?? 'unfulfilled'),
                'note' => $node['note'] ?? null,
                'customer' => $customer,
                'line_items' => $lineItems,
            ];
        }

        return $orders;
    }

    /**
     * Fetch customers from Shopify Admin GraphQL API.
     */
    public function fetchCustomers(Shop $shop, int $limit = 50): array
    {
        $token = $this->getValidAccessToken($shop);

        $query = <<<'GRAPHQL'
query fetchCustomers($first: Int!) {
    customers(first: $first) {
        nodes {
            id
            firstName
            lastName
            email
            defaultAddress {
                address1
                city
                province
                zip
                country
            }
        }
    }
}
GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post("https://{$shop->shop_domain}/admin/api/2026-07/graphql.json", [
            'query' => $query,
            'variables' => ['first' => $limit],
        ]);

        if (!$response->successful()) {
            Log::error('Failed to fetch Shopify customers via GraphQL', [
                'shop' => $shop->shop_domain,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->status() === 403) {
                $shop->update(['scope' => null]);
            }

            throw new \Exception("Failed to fetch Shopify customers (HTTP {$response->status()}): {$response->body()}");
        }

        $data = $response->json();
        if (!empty($data['errors'])) {
            Log::error('GraphQL errors fetching customers', ['errors' => $data['errors']]);
            throw new \Exception('GraphQL errors fetching customers: ' . json_encode($data['errors']));
        }

        $nodes = $data['data']['customers']['nodes'] ?? [];
        $customers = [];

        foreach ($nodes as $node) {
            $numericId = preg_replace('/[^0-9]/', '', $node['id'] ?? '');
            $customers[] = [
                'id' => $numericId,
                'first_name' => $node['firstName'] ?? '',
                'last_name' => $node['lastName'] ?? '',
                'email' => $node['email'] ?? '',
                'phone' => $node['phone'] ?? null,
                'default_address' => $node['defaultAddress'] ?? [],
            ];
        }

        return $customers;
    }

    /**
     * Register customers/create and customers/update webhooks.
     */
    public function registerCustomerUpdateWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'CUSTOMERS_UPDATE',
            '/webhooks/customers',
            'Customer update'
        );
    }

    /**
     * Register orders/create webhook.
     */
    public function registerOrderCreateWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'ORDERS_CREATE',
            '/webhooks/orders',
            'Order create'
        );
    }

    /**
     * Register orders/updated webhook.
     */
    public function registerOrderUpdateWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'ORDERS_UPDATED',
            '/webhooks/orders',
            'Order update'
        );
    }

    /**
     * Register all order-related webhooks (orders/create & orders/updated).
     */
    public function registerOrderWebhooks(Shop $shop): array
    {
        $createResult = $this->registerOrderCreateWebhook($shop);
        $updateResult = $this->registerOrderUpdateWebhook($shop);

        return [
            'success' => ($createResult['success'] ?? false) && ($updateResult['success'] ?? false),
            'create' => $createResult,
            'update' => $updateResult,
        ];
    }
}

