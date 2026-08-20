<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
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
     * Helper method to extract money values prioritizing presentmentMoney over shopMoney.
     */
    public static function extractMoney(?array $priceSet, string $defaultCurrency = 'USD'): array
    {
        if (empty($priceSet)) {
            return ['currency' => strtoupper(trim($defaultCurrency)), 'amount' => 0.00];
        }

        $presentment = $priceSet['presentmentMoney'] ?? $priceSet['presentment_money'] ?? null;
        $shop = $priceSet['shopMoney'] ?? $priceSet['shop_money'] ?? null;

        if (!empty($presentment) && is_array($presentment) && isset($presentment['amount'])) {
            $currency = !empty($presentment['currencyCode'])
                ? $presentment['currencyCode']
                : (!empty($presentment['currency_code']) ? $presentment['currency_code'] : $defaultCurrency);
            $amount = (float) ($presentment['amount'] ?? 0.00);
            return ['currency' => strtoupper(trim((string) $currency)), 'amount' => $amount];
        }

        if (!empty($shop) && is_array($shop) && isset($shop['amount'])) {
            $currency = !empty($shop['currencyCode'])
                ? $shop['currencyCode']
                : (!empty($shop['currency_code']) ? $shop['currency_code'] : $defaultCurrency);
            $amount = (float) ($shop['amount'] ?? 0.00);
            return ['currency' => strtoupper(trim((string) $currency)), 'amount' => $amount];
        }

        return ['currency' => strtoupper(trim($defaultCurrency)), 'amount' => 0.00];
    }

    /**
     * Canonical money resolver for REST & GraphQL payloads.
     * Preferred order: presentment_money / presentmentMoney -> shop_money / shopMoney -> raw scalar fallback.
     */
    public static function extractRestMoney(?array $priceSet, string $fallbackCurrency = 'USD', float|string $rawAmount = 0.00): array
    {
        if (!empty($priceSet) && is_array($priceSet)) {
            $extracted = self::extractMoney($priceSet, $fallbackCurrency);
            if (!empty($extracted['currency']) && ($extracted['amount'] > 0 || isset($priceSet['presentment_money']) || isset($priceSet['presentmentMoney']))) {
                return $extracted;
            }
        }

        return [
            'currency' => strtoupper(trim((string) $fallbackCurrency)),
            'amount' => (float) $rawAmount,
        ];
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
     * Fetch shop metadata (including base currencyCode) from Shopify and update local Shop model.
     */
    public function fetchShopDetails(Shop $shop): array
    {
        $accessToken = $this->getValidAccessToken($shop);

        $query = <<<'GRAPHQL'
query GetShopMetadata {
    shop {
        name
        email
        currencyCode
        myshopifyDomain
    }
}
GRAPHQL;

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->post("https://{$shop->shop_domain}/admin/api/2026-07/graphql.json", [
                'query' => $query,
            ]);

            if (!$response->successful()) {
                throw new \Exception("Shopify GraphQL error: " . $response->body());
            }

            $data = $response->json();
            $shopData = $data['data']['shop'] ?? [];
            if (!empty($shopData['currencyCode'])) {
                $currency = strtoupper(trim($shopData['currencyCode']));
                $shop->update(['currency' => $currency]);
            }
            return $shopData;
        } catch (\Throwable $e) {
            Log::warning("fetchShopDetails failed for shop {$shop->shop_domain}: " . $e->getMessage());
            return [
                'currencyCode' => $shop->currency ?? 'USD',
                'myshopifyDomain' => $shop->shop_domain,
            ];
        }
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
     * Register products/delete webhook.
     */
    public function registerProductDeleteWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'PRODUCTS_DELETE',
            '/webhooks/products/delete',
            'Product delete'
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
        $existingWebhookToUpdate = null;

        foreach ($existingWebhooks as $webhook) {
            if ($webhook['topic'] === $topic) {
                if ($webhook['uri'] === $webhookUrl) {
                    return [
                        'success' => true,
                        'created' => false,
                        'message' => "{$label} webhook already exists.",
                        'webhook_id' => $webhook['id'],
                        'uri' => $webhook['uri'],
                    ];
                }
                $existingWebhookToUpdate = $webhook;
                break;
            }
        }

        if ($existingWebhookToUpdate) {
            $updateMutation = <<<'GRAPHQL'
mutation webhookSubscriptionUpdate(
    $id: ID!,
    $webhookSubscription: WebhookSubscriptionInput!
) {
    webhookSubscriptionUpdate(
        id: $id,
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

            $updateResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->post(
                    "https://{$shop->shop_domain}/admin/api/2026-07/graphql.json",
                    [
                        'query' => $updateMutation,
                        'variables' => [
                            'id' => $existingWebhookToUpdate['id'],
                            'webhookSubscription' => [
                                'uri' => $webhookUrl,
                            ],
                        ],
                    ]
                );

            if (!$updateResponse->successful()) {
                throw new \Exception(
                    "Failed to update Shopify webhook for {$label}: " . $updateResponse->body()
                );
            }

            $updateData = $updateResponse->json();
            $updateResult = $updateData['data']['webhookSubscriptionUpdate'] ?? null;

            if (!$updateResult || !empty($updateResult['userErrors'])) {
                throw new \Exception(
                    "Shopify webhook update errors for {$label}: " . json_encode($updateResult['userErrors'] ?? [])
                );
            }

            return [
                'success' => true,
                'created' => false,
                'updated' => true,
                'message' => "{$label} webhook updated successfully.",
                'webhook_id' => $updateResult['webhookSubscription']['id'] ?? null,
                'uri' => $updateResult['webhookSubscription']['uri'] ?? $webhookUrl,
            ];
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
     * Retrieve current available inventory quantity for a specific InventoryItem ID and Location ID.
     */
    public function getCurrentAvailableQuantity(Shop $shop, string $inventoryItemId, string $locationId): ?int
    {
        $accessToken = $this->getValidAccessToken($shop);

        $formattedInventoryItemId = str_starts_with($inventoryItemId, 'gid://')
            ? $inventoryItemId
            : "gid://shopify/InventoryItem/{$inventoryItemId}";

        $formattedLocationId = str_starts_with($locationId, 'gid://')
            ? $locationId
            : "gid://shopify/Location/{$locationId}";

        $query = <<<'GRAPHQL'
query GetInventoryItemQuantity($id: ID!) {
    inventoryItem(id: $id) {
        id
        inventoryLevels(first: 25) {
            nodes {
                location {
                    id
                }
                quantities(names: ["available"]) {
                    name
                    quantity
                }
            }
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
                    'variables' => [
                        'id' => $formattedInventoryItemId,
                    ],
                ]
            );

        if (!$response->successful()) {
            Log::error("getCurrentAvailableQuantity: HTTP request failed: " . $response->body());
            return null;
        }

        $data = $response->json();

        if (!empty($data['errors'])) {
            Log::error("getCurrentAvailableQuantity: GraphQL error: " . json_encode($data['errors']));
            return null;
        }

        $inventoryItem = $data['data']['inventoryItem'] ?? null;
        if (!$inventoryItem) {
            Log::warning("getCurrentAvailableQuantity: inventoryItem null for ID {$formattedInventoryItemId}");
            return null;
        }

        $nodes = $inventoryItem['inventoryLevels']['nodes'] ?? [];

        foreach ($nodes as $node) {
            $nodeLocationId = $node['location']['id'] ?? '';
            if ($nodeLocationId === $formattedLocationId) {
                $quantities = $node['quantities'] ?? [];
                foreach ($quantities as $q) {
                    if (($q['name'] ?? '') === 'available' && isset($q['quantity'])) {
                        return (int) $q['quantity'];
                    }
                }
                if (isset($quantities[0]['quantity'])) {
                    return (int) $quantities[0]['quantity'];
                }
            }
        }

        if (!empty($nodes) && isset($nodes[0]['quantities'][0]['quantity'])) {
            return (int) $nodes[0]['quantities'][0]['quantity'];
        }

        return null;
    }

    /**
     * Retrieve aggregate store-wide available inventory quantity across ALL active fulfillment locations.
     */
    public function fetchStorewideAvailableQuantity(Shop $shop, string $inventoryItemId): ?int
    {
        $accessToken = $this->getValidAccessToken($shop);

        $formattedInventoryItemId = str_starts_with($inventoryItemId, 'gid://')
            ? $inventoryItemId
            : "gid://shopify/InventoryItem/{$inventoryItemId}";

        $query = <<<'GRAPHQL'
query GetStorewideInventoryQuantity($id: ID!) {
    inventoryItem(id: $id) {
        id
        inventoryLevels(first: 50) {
            nodes {
                location {
                    id
                    isActive
                }
                quantities(names: ["available"]) {
                    name
                    quantity
                }
            }
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
                'variables' => [
                    'id' => $formattedInventoryItemId,
                ],
            ]
        );

        if (!$response->successful()) {
            Log::error("fetchStorewideAvailableQuantity: HTTP request failed: " . $response->body());
            return null;
        }

        $data = $response->json();
        if (!empty($data['errors'])) {
            Log::error("fetchStorewideAvailableQuantity: GraphQL error: " . json_encode($data['errors']));
            return null;
        }

        $nodes = $data['data']['inventoryItem']['inventoryLevels']['nodes'] ?? [];
        if (empty($nodes)) {
            return null;
        }

        $totalAvailable = 0;
        $foundAvailable = false;

        foreach ($nodes as $node) {
            $isActive = $node['location']['isActive'] ?? true;
            if (!$isActive) {
                continue;
            }

            foreach ($node['quantities'] ?? [] as $q) {
                if (($q['name'] ?? '') === 'available' && isset($q['quantity'])) {
                    $totalAvailable += (int) $q['quantity'];
                    $foundAvailable = true;
                }
            }
        }

        return $foundAvailable ? $totalAvailable : null;
    }

    /**
     * Update inventory quantity on Shopify for a specific InventoryItem ID.
     * Clamps quantity to max(0, $quantity) to prevent overselling.
     * Uses compare-and-swap via changeFromQuantity and retries once on race conditions.
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

        $maxAttempts = 2;
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            $currentQuantity = $this->getCurrentAvailableQuantity($shop, $formattedInventoryItemId, $formattedLocationId);

            if ($currentQuantity === null) {
                throw new \Exception("Could not fetch current Shopify inventory quantity for item {$formattedInventoryItemId} at location {$formattedLocationId}.");
            }

            $mutation = <<<'GRAPHQL'
mutation inventorySetQuantities(
    $input: InventorySetQuantitiesInput!
    $idempotencyKey: String!
) {
    inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
        inventoryAdjustmentGroup {
            id
        }
        userErrors {
            field
            message
            code
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
                                'quantities' => [
                                    [
                                        'inventoryItemId' => $formattedInventoryItemId,
                                        'locationId' => $formattedLocationId,
                                        'quantity' => $safeQuantity,
                                        'changeFromQuantity' => $currentQuantity,
                                    ],
                                ],
                            ],
                            'idempotencyKey' => bin2hex(random_bytes(16)),
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
            $userErrors = $result['userErrors'] ?? [];

            if (!empty($userErrors)) {
                $isCompareMismatch = false;
                foreach ($userErrors as $uErr) {
                    $msg = strtolower($uErr['message'] ?? '');
                    $fieldStr = json_encode($uErr['field'] ?? []);
                    if (str_contains($msg, 'changefromquantity') || str_contains($msg, 'compare') || str_contains($msg, 'mismatch') || str_contains($fieldStr, 'changeFromQuantity')) {
                        $isCompareMismatch = true;
                        break;
                    }
                }

                if ($isCompareMismatch && $attempt < $maxAttempts) {
                    Log::warning("setInventoryQuantity: compare-and-swap mismatch on attempt {$attempt} for item {$formattedInventoryItemId}. Retrying once...");
                    continue;
                }

                throw new \Exception('Shopify inventory update user errors: ' . json_encode($userErrors));
            }

            return [
                'success' => true,
                'shopify_inventory_item_id' => $formattedInventoryItemId,
                'location_id' => $formattedLocationId,
                'quantity' => $safeQuantity,
                'change_from_quantity' => $currentQuantity,
                'response' => $data,
            ];
        }

        throw $lastException ?? new \Exception('Shopify inventory update failed after retry.');
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
            taxesIncluded
            taxLines { title rate priceSet { shopMoney { amount } } }
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
                    taxLines { title rate priceSet { shopMoney { amount } } }
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
                $customerDefaultAddr = $custNode['defaultAddress'] ?? [];
                $customer = [
                    'id' => $custNumericId,
                    'first_name' => $custNode['firstName'] ?? '',
                    'last_name' => $custNode['lastName'] ?? '',
                    'email' => $custNode['email'] ?? '',
                    'phone' => !empty($custNode['phone']) ? $custNode['phone'] : ($customerDefaultAddr['phone'] ?? null),
                    'default_address' => $customerDefaultAddr,
                ];
            }

            $lineItems = [];
            foreach ($node['lineItems']['nodes'] ?? [] as $li) {
                $liTaxLines = [];
                foreach ($li['taxLines'] ?? [] as $tl) {
                    $liTaxLines[] = [
                        'title' => $tl['title'] ?? '',
                        'price' => (float) ($tl['priceSet']['shopMoney']['amount'] ?? 0.00),
                        'rate' => (float) ($tl['rate'] ?? 0.0),
                    ];
                }

                $lineItems[] = [
                    'id' => preg_replace('/[^0-9]/', '', $li['id'] ?? ''),
                    'title' => $li['title'] ?? '',
                    'quantity' => $li['quantity'] ?? 1,
                    'price' => $li['originalUnitPriceSet']['shopMoney']['amount'] ?? '0.00',
                    'sku' => $li['variant']['sku'] ?? '',
                    'variant_id' => !empty($li['variant']['id']) ? preg_replace('/[^0-9]/', '', $li['variant']['id']) : null,
                    'tax_lines' => $liTaxLines,
                ];
            }

            $orderTaxLines = [];
            foreach ($node['taxLines'] ?? [] as $tl) {
                $orderTaxLines[] = [
                    'title' => $tl['title'] ?? '',
                    'price' => (float) ($tl['priceSet']['shopMoney']['amount'] ?? 0.00),
                    'rate' => (float) ($tl['rate'] ?? 0.0),
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
                'taxes_included' => (bool) ($node['taxesIncluded'] ?? false),
                'tax_lines' => $orderTaxLines,
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
     * Fetch a single order by Shopify Order ID (numeric or GID) via REST API.
     */
    public function fetchOrderById(Shop $shop, string $orderId): ?array
    {
        $numericId = preg_replace('/^gid:\/\/shopify\/Order\//', '', $orderId);
        $token = $this->getValidAccessToken($shop);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->get("https://{$shop->shop_domain}/admin/api/2026-07/orders/{$numericId}.json");

        if ($response->successful()) {
            return $response->json('order');
        }

        Log::warning("fetchOrderById: Failed to fetch order {$numericId} for shop {$shop->shop_domain}", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

    /**
     * Fetch a single order by ID from Shopify Admin GraphQL API and sync it locally & to Zoho.
     */
    public function fetchAndSyncOrder(Shop $shop, string $shopifyOrderId): ?Order
    {
        $numericId = preg_replace('/[^0-9]/', '', $shopifyOrderId);
        $gid = "gid://shopify/Order/{$numericId}";

        $token = $this->getValidAccessToken($shop);

        $query = <<<'GRAPHQL'
query fetchSingleOrder($id: ID!) {
    order(id: $id) {
        id
        name
        createdAt
        currencyCode
        taxesIncluded
        taxLines { title rate priceSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } } }
        subtotalPriceSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } }
        totalDiscountsSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } }
        totalShippingPriceSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } }
        totalTaxSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } }
        totalPriceSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } }
        displayFinancialStatus
        displayFulfillmentStatus
        cancelledAt
        cancelReason
        note
        shippingAddress {
            firstName
            lastName
            name
            company
            address1
            address2
            city
            province
            provinceCode
            zip
            country
            phone
        }
        shippingLines(first: 10) {
            nodes {
                title
                originalPriceSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } }
                code
            }
        }
        fulfillments {
            status
            createdAt
            trackingInfo {
                company
                number
                url
            }
        }
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
                originalUnitPriceSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } }
                taxLines { title rate priceSet { presentmentMoney { amount currencyCode } shopMoney { amount currencyCode } } }
                variant {
                    id
                    sku
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
                    'variables' => ['id' => $gid],
                ]);

        if (!$response->successful()) {
            Log::error("Failed to fetch Shopify order {$gid} via GraphQL", [
                'shop' => $shop->shop_domain,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        $node = $data['data']['order'] ?? null;

        if (!$node) {
            Log::warning("Shopify order {$gid} not found in GraphQL response.");
            return null;
        }

        $custNode = $node['customer'] ?? null;
        $customerId = null;
        if ($custNode) {
            $rawCustId = (string) ($custNode['id'] ?? '');
            $shopifyCustId = str_starts_with($rawCustId, 'gid://')
                ? $rawCustId
                : "gid://shopify/Customer/" . preg_replace('/[^0-9]/', '', $rawCustId);

            $defaultAddr = $custNode['defaultAddress'] ?? [];
            $phone = !empty($custNode['phone']) ? $custNode['phone'] : ($defaultAddr['phone'] ?? null);

            $updateData = [
                'first_name' => $custNode['firstName'] ?? null,
                'last_name' => $custNode['lastName'] ?? null,
                'email' => $custNode['email'] ?? null,
                'billing_address' => $defaultAddr,
                'shipping_address' => $defaultAddr,
            ];
            if ($phone !== null) {
                $updateData['phone'] = $phone;
            }

            $customer = Customer::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_customer_id' => $shopifyCustId,
                ],
                $updateData
            );

            $customerId = $customer->id;

            if ($shop->zohoConnection) {
                try {
                    $zohoService = new ZohoService($shop);
                    $zohoService->syncCustomer($customer);
                } catch (\Throwable $e) {
                    Log::warning("fetchAndSyncOrder: Customer sync pre-step failed for order customer ID {$customer->id}: " . $e->getMessage());
                }
            }
        }

        // Single Source of Truth Currency Resolution
        $resolvedTotal = self::extractMoney($node['totalPriceSet'] ?? [], $node['currencyCode'] ?? 'USD');
        $orderCurrency = $resolvedTotal['currency'];
        $totalPrice = $resolvedTotal['amount'];

        $subtotalData = self::extractMoney($node['subtotalPriceSet'] ?? [], $orderCurrency);
        $subtotal = $subtotalData['amount'];

        $discountData = self::extractMoney($node['totalDiscountsSet'] ?? [], $orderCurrency);
        $discountTotal = $discountData['amount'];

        $shippingData = self::extractMoney($node['totalShippingPriceSet'] ?? [], $orderCurrency);
        $shippingTotal = $shippingData['amount'];

        $taxData = self::extractMoney($node['totalTaxSet'] ?? [], $orderCurrency);
        $taxTotal = $taxData['amount'];

        $lineItems = [];
        foreach ($node['lineItems']['nodes'] ?? [] as $li) {
            $liTaxLines = [];
            foreach ($li['taxLines'] ?? [] as $tl) {
                $tlMoney = self::extractMoney($tl['priceSet'] ?? [], $orderCurrency);
                $liTaxLines[] = [
                    'title' => $tl['title'] ?? '',
                    'price' => $tlMoney['amount'],
                    'rate' => (float) ($tl['rate'] ?? 0.0),
                ];
            }

            $liUnitPrice = self::extractMoney($li['originalUnitPriceSet'] ?? [], $orderCurrency);
            $lineItems[] = [
                'line_item_id' => preg_replace('/[^0-9]/', '', $li['id'] ?? ''),
                'title' => $li['title'] ?? '',
                'quantity' => (int) ($li['quantity'] ?? 1),
                'price' => $liUnitPrice['amount'],
                'sku' => $li['sku'] ?? ($li['variant']['sku'] ?? ''),
                'variant_id' => !empty($li['variant']['id']) ? preg_replace('/[^0-9]/', '', $li['variant']['id']) : null,
                'tax_lines' => $liTaxLines,
            ];
        }

        $orderTaxLines = [];
        foreach ($node['taxLines'] ?? [] as $tl) {
            $tlMoney = self::extractMoney($tl['priceSet'] ?? [], $orderCurrency);
            $orderTaxLines[] = [
                'title' => $tl['title'] ?? '',
                'price' => $tlMoney['amount'],
                'rate' => (float) ($tl['rate'] ?? 0.0),
            ];
        }

        $shippingAddress = !empty($node['shippingAddress']) ? [
            'first_name' => $node['shippingAddress']['firstName'] ?? null,
            'last_name' => $node['shippingAddress']['lastName'] ?? null,
            'name' => $node['shippingAddress']['name'] ?? null,
            'company' => $node['shippingAddress']['company'] ?? null,
            'address1' => $node['shippingAddress']['address1'] ?? null,
            'address2' => $node['shippingAddress']['address2'] ?? null,
            'city' => $node['shippingAddress']['city'] ?? null,
            'province' => $node['shippingAddress']['province'] ?? null,
            'province_code' => $node['shippingAddress']['provinceCode'] ?? null,
            'zip' => $node['shippingAddress']['zip'] ?? null,
            'country' => $node['shippingAddress']['country'] ?? null,
            'phone' => $node['shippingAddress']['phone'] ?? null,
        ] : null;

        $shippingLines = [];
        $shippingMethod = null;
        foreach ($node['shippingLines']['nodes'] ?? [] as $sl) {
            $slMoney = self::extractMoney($sl['originalPriceSet'] ?? [], $orderCurrency);
            $slTitle = $sl['title'] ?? '';
            $shippingLines[] = [
                'title' => $slTitle,
                'price' => $slMoney['amount'],
                'code' => $sl['code'] ?? null,
            ];
            if (empty($shippingMethod) && !empty($slTitle)) {
                $shippingMethod = $slTitle;
            }
        }

        $fulfillments = [];
        $trackingNumber = null;
        $trackingCompany = null;
        $trackingUrl = null;

        foreach ($node['fulfillments'] ?? [] as $ful) {
            $tInfos = $ful['trackingInfo'] ?? [];
            foreach ($tInfos as $ti) {
                if (!empty($ti['number']) && empty($trackingNumber)) {
                    $trackingNumber = $ti['number'];
                }
                if (!empty($ti['company']) && empty($trackingCompany)) {
                    $trackingCompany = $ti['company'];
                }
                if (!empty($ti['url']) && empty($trackingUrl)) {
                    $trackingUrl = $ti['url'];
                }
            }
            $fulfillments[] = [
                'status' => $ful['status'] ?? null,
                'created_at' => $ful['createdAt'] ?? null,
                'tracking_info' => $tInfos,
            ];
        }

        $taxesIncluded = (bool) ($node['taxesIncluded'] ?? false);

        $existingOrder = Order::where('shop_id', $shop->id)
            ->where('shopify_order_id', $gid)
            ->first();

        $updateData = [
            'customer_id' => $customerId,
            'order_number' => $node['name'] ?? "#{$numericId}",
            'order_date' => !empty($node['createdAt']) ? date('Y-m-d H:i:s', strtotime($node['createdAt'])) : now(),
            'currency' => $orderCurrency,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'shipping_total' => $shippingTotal,
            'shipping_method' => $shippingMethod,
            'shipping_address' => $shippingAddress,
            'shipping_lines' => $shippingLines,
            'tracking_number' => $trackingNumber,
            'tracking_company' => $trackingCompany,
            'tracking_url' => $trackingUrl,
            'fulfillments' => $fulfillments,
            'tax_total' => $taxTotal,
            'total_price' => $totalPrice,
            'financial_status' => strtolower($node['displayFinancialStatus'] ?? 'pending'),
            'fulfillment_status' => strtolower($node['displayFulfillmentStatus'] ?? 'unfulfilled'),
            'line_items' => $lineItems,
            'tax_lines' => $orderTaxLines,
            'taxes_included' => $taxesIncluded,
            'notes' => $node['note'] ?? null,
        ];

        if (!empty($node['cancelledAt'])) {
            $updateData['cancelled_at'] = date('Y-m-d H:i:s', strtotime($node['cancelledAt']));
            $updateData['cancel_reason'] = !empty($node['cancelReason']) ? strtolower($node['cancelReason']) : null;
        } elseif ($existingOrder && $existingOrder->cancelled_at) {
            $updateData['cancelled_at'] = $existingOrder->cancelled_at;
            $updateData['cancel_reason'] = $existingOrder->cancel_reason;
        }

        $order = Order::updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_order_id' => $gid,
            ],
            $updateData
        );

        if ($shop->zohoConnection) {
            try {
                $zohoService = new ZohoService($shop);
                $zohoService->syncOrder($order);
                try {
                    $zohoService->syncInvoice($order);
                } catch (\Throwable $invEx) {
                    Log::warning("fetchAndSyncOrder: Zoho invoice auto-sync warning for order ID {$order->id}: " . $invEx->getMessage());
                }
            } catch (\Throwable $e) {
                Log::error("fetchAndSyncOrder: Zoho order sync failed for order ID {$order->id}: " . $e->getMessage());
            }
        }

        return $order->fresh();
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
            $defaultAddr = $node['defaultAddress'] ?? [];
            $phone = !empty($node['phone']) ? $node['phone'] : ($defaultAddr['phone'] ?? null);
            $customers[] = [
                'id' => $numericId,
                'first_name' => $node['firstName'] ?? '',
                'last_name' => $node['lastName'] ?? '',
                'email' => $node['email'] ?? '',
                'phone' => $phone,
                'default_address' => $defaultAddr,
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
     * Register order_transactions/create webhook.
     */
    public function registerOrderTransactionCreateWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'ORDER_TRANSACTIONS_CREATE',
            '/webhooks/order-transactions',
            'Order transaction create'
        );
    }

    /**
     * Register refunds/create webhook.
     */
    public function registerRefundCreateWebhook(Shop $shop): array
    {
        return $this->registerWebhookSubscription(
            $shop,
            'REFUNDS_CREATE',
            '/webhooks/refunds',
            'Refund create'
        );
    }

    /**
     * Register all order-related webhooks (orders/create, orders/updated, order_transactions/create, refunds/create).
     */
    public function registerOrderWebhooks(Shop $shop): array
    {
        $createResult = $this->registerOrderCreateWebhook($shop);
        $updateResult = $this->registerOrderUpdateWebhook($shop);
        $txnResult = $this->registerOrderTransactionCreateWebhook($shop);
        $refundResult = $this->registerRefundCreateWebhook($shop);

        return [
            'success' => ($createResult['success'] ?? false) && ($updateResult['success'] ?? false) && ($txnResult['success'] ?? false) && ($refundResult['success'] ?? false),
            'create' => $createResult,
            'update' => $updateResult,
            'transaction' => $txnResult,
            'refund' => $refundResult,
        ];
    }

    /**
     * Register all required Shopify webhooks for the application.
     */
    public function registerAllWebhooks(Shop $shop): array
    {
        $productResult = $this->registerProductUpdateWebhook($shop);
        $productDeleteResult = $this->registerProductDeleteWebhook($shop);
        $inventoryResult = $this->registerInventoryLevelUpdateWebhook($shop);
        $customerResult = $this->registerCustomerUpdateWebhook($shop);
        $orderCreateResult = $this->registerOrderCreateWebhook($shop);
        $orderUpdateResult = $this->registerOrderUpdateWebhook($shop);
        $txnResult = $this->registerOrderTransactionCreateWebhook($shop);
        $refundResult = $this->registerRefundCreateWebhook($shop);

        return [
            'success' => ($productResult['success'] ?? false)
                && ($productDeleteResult['success'] ?? false)
                && ($inventoryResult['success'] ?? false)
                && ($customerResult['success'] ?? false)
                && ($orderCreateResult['success'] ?? false)
                && ($orderUpdateResult['success'] ?? false)
                && ($txnResult['success'] ?? false)
                && ($refundResult['success'] ?? false),
            'product' => $productResult,
            'product_delete' => $productDeleteResult,
            'inventory' => $inventoryResult,
            'customer' => $customerResult,
            'order_create' => $orderCreateResult,
            'order_update' => $orderUpdateResult,
            'transaction' => $txnResult,
            'refund' => $refundResult,
        ];
    }

    /**
     * Ensure all webhooks are registered for a shop, cached per shop & app URL.
     */
    public function ensureWebhooksRegistered(Shop $shop): array
    {
        $appUrl = env('SHOPIFY_APP_URL', '');
        $cacheKey = 'shopify_webhooks_registered_' . $shop->id . '_' . md5($appUrl);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($shop) {
            try {
                return $this->registerAllWebhooks($shop);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("ensureWebhooksRegistered failed for shop {$shop->shop_domain}: " . $e->getMessage());
                return ['success' => false, 'error' => $e->getMessage()];
            }
        });
    }
}

