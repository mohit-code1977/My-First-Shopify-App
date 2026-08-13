<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Models\SyncHistory;
use Illuminate\Support\Facades\Http;

class ZohoService
{
    private const SHOPIFY_VARIANT_FIELD_API_NAME =
    'cf_shopify_variant_id';

    // Current Shopify shop
    protected Shop $shop;

    // Store the Shopify shop when creating the service
    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    // Get the Zoho connection for the current Shopify shop
    public function getConnection(): ZohoConnection
    {
        $connection = $this->shop->zohoConnection;

        if (!$connection) {
            throw new \Exception('Zoho is not connected for this shop.');
        }

        return $connection;
    }

    // Return a valid Zoho access token
    public function getAccessToken(): string
    {
        $connection = $this->getConnection();

        if ($connection->expires_at->isFuture()) {
            return $connection->access_token;
        }

        return $this->refreshAccessToken();
    }

    // Generate a new access token using the stored refresh token
    public function refreshAccessToken(): string
    {
        $connection = $this->getConnection();

        $response = Http::asForm()->post(
            env('ZOHO_ACCOUNTS_URL') . '/oauth/v2/token',
            [
                'refresh_token' => $connection->refresh_token,
                'client_id' => env('ZOHO_CLIENT_ID'),
                'client_secret' => env('ZOHO_CLIENT_SECRET'),
                'grant_type' => 'refresh_token',
            ]
        );

        if (!$response->successful()) {
            throw new \Exception('Failed to refresh Zoho access token.');
        }

        $tokenData = $response->json();

        $accessToken = $tokenData['access_token'] ?? null;

        if (!$accessToken) {
            throw new \Exception('Zoho did not return a new access token.');
        }

        $connection->update([
            'access_token' => $accessToken,
            'expires_at' => now()->addSeconds(
                $tokenData['expires_in'] ?? 3600
            ),
        ]);

        return $accessToken;
    }

    // Make an authenticated request to the Zoho Books API
    public function makeRequest(
        string $method,
        string $endpoint,
        array $data = []
    ): array {
        $connection = $this->getConnection();

        $token = $this->getAccessToken();

        $url = rtrim(env('ZOHO_API_URL'), '/') . $endpoint;

        // Send organization ID as a query parameter
        $query = [
            'organization_id' => $connection->organization_id,
        ];

        // Create the authenticated HTTP request
        $request = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Accept' => 'application/json',
        ]);

        // Send GET parameters as query parameters
        if (strtoupper($method) === 'GET') {
            $response = $request->get(
                $url,
                array_merge($query, $data)
            );
        } else {
            // Send POST/PUT data as JSON body
            $response = $request
                ->withQueryParameters($query)
                ->{$method}($url, $data);
        }

        // Throw an exception when Zoho returns an unsuccessful response
        if (!$response->successful()) {
            throw new \Exception(
                'Zoho API request failed: ' . $response->body()
            );
        }

        return $response->json();
    }

    // Get all items from Zoho Books
    public function getItems(): array
    {
        $allItems = [];

        $page = 1;
        $perPage = 200;

        do {
            $response = $this->makeRequest(
                'GET',
                '/books/v3/items',
                [
                    'page' => $page,
                    'per_page' => $perPage,
                ]
            );

            $items = $response['items'] ?? [];

            foreach ($items as $item) {
                $allItems[] = $item;
            }

            $pageContext =
                $response['page_context']
                ?? [];

            $hasMorePage =
                (bool) (
                    $pageContext['has_more_page']
                    ?? false
                );

            $page++;
        } while ($hasMorePage);

        return $allItems;
    }



    public function getShopifyVariantMappings(): array
    {
        $fieldId = $this->getShopifyVariantFieldId();

        $items = $this->getItems();

        $mappings = [];

        foreach ($items as $item) {
            $customFields = $item['custom_fields'] ?? [];

            foreach ($customFields as $customField) {
                if (
                    (string) (
                        $customField['customfield_id'] ?? '') !== (string) $fieldId
                ) {
                    continue;
                }

                $shopifyVariantId =
                    $customField['value']
                    ?? null;

                $zohoItemId =
                    $item['item_id']
                    ?? null;

                if (
                    !$shopifyVariantId ||
                    !$zohoItemId
                ) {
                    continue;
                }

                $mappings[(string) $shopifyVariantId] = [
                    'zoho_item_id' =>
                    (string) $zohoItemId,

                    'zoho_item' =>
                    $item,
                ];

                break;
            }
        }

        return $mappings;
    }


    private function getShopifyVariantFieldId(): string
    {
        $response = $this->makeRequest(
            'GET',
            '/books/v3/settings/fields',
            [
                'entity' => 'item',
                'filter_custom_fields' => 'true',
                'skip_inactive_fields' => 'true',
            ]
        );

        $fields = $response['fields'] ?? [];

        foreach ($fields as $field) {
            if (
                ($field['api_name'] ?? null) ===
                self::SHOPIFY_VARIANT_FIELD_API_NAME
            ) {
                return (string) $field['field_id'];
            }
        }

        throw new \Exception(
            'Zoho custom field "' .
                self::SHOPIFY_VARIANT_FIELD_API_NAME .
                '" was not found.'
        );
    }


    public function findItemByShopifyVariantId(
        string $shopifyVariantId
    ): ?array {
        $fieldId = $this->getShopifyVariantFieldId();

        $items = $this->getItems();

        foreach ($items as $item) {
            $customFields =
                $item['custom_fields'] ?? [];

            foreach ($customFields as $customField) {
                if ((string) (
                        $customField['customfield_id']?? '') !== (string) $fieldId
                ) {
                    continue;
                }

                $value = $customField['value'] ?? null;

                if (
                    $value !== null &&
                    (string) $value ===
                    $shopifyVariantId
                ) {
                    return $item;
                }
            }
        }

        return null;
    }


    // Create a Zoho Books item from a Shopify product variant
    public function createItem(ProductVariant $variant): array {
        // Prevent duplicate Zoho item creation
        if ($variant->zoho_item_id) {
            return [
                'message' => 'Zoho item already exists for this variant.',
                'zoho_item_id' => $variant->zoho_item_id,
                'created' => false,
                'updated' => false,
            ];
        }

        // Get the parent Shopify product
        $product = $variant->product;

        // Build the Zoho item data
        $shopifyVariantFieldId = $this->getShopifyVariantFieldId();

        $data = [
            'name' =>
            $product->title .
                ' - ' .
                $variant->title,

            'rate' =>
            (float) $variant->price,

            'product_type' =>
            'goods',

            'custom_fields' => [
                [
                    'customfield_id' =>
                    $shopifyVariantFieldId,

                    'value' =>
                    $variant->shopify_variant_id,
                ],
            ],
        ];
        // Add SKU only when Shopify has one
        if (!empty($variant->sku)) {
            $data['sku'] = $variant->sku;
        }

        // Create the item in Zoho Books
        $result = $this->makeRequest('POST', '/books/v3/items', $data);

        // Get the Zoho item ID from the API response
        $zohoItemId = $result['item']['item_id'] ?? null;

        // Make sure Zoho returned an item ID
        if (!$zohoItemId) {
            throw new \Exception('Zoho did not return an item ID.');
        }

        // Save the Zoho item ID against the Shopify variant
        $variant->update([
            'zoho_item_id' => $zohoItemId,
        ]);

        // Return sync information along with the Zoho response
        return [
            'message' => 'Zoho item created successfully.',
            'zoho_item_id' => $zohoItemId,
            'created' => true,
            'updated' => false,
            'zoho_response' => $result,
        ];
    }

    // Update an existing Zoho Books item using Shopify variant data
    public function updateItem(ProductVariant $variant): array  {
        // Make sure the variant is already linked to a Zoho item
        if (!$variant->zoho_item_id) {
            throw new \Exception(
                'Cannot update Zoho item because this variant is not synced yet.'
            );
        }

        // Get the parent Shopify product
        $product = $variant->product;

        // Build the updated Zoho item data
        $data = [
            'name' => $product->title . ' - ' . $variant->title,
            'rate' => (float) $variant->price,
            'product_type' => 'goods',
        ];

        // Include SKU only when Shopify has one
        if (!empty($variant->sku)) {
            $data['sku'] = $variant->sku;
        }

        // Update the existing item in Zoho Books
        $result = $this->makeRequest(
            'PUT',
            '/books/v3/items/' . $variant->zoho_item_id,
            $data
        );

        // Return sync information along with the Zoho response
        return [
            'message' => 'Zoho item updated successfully.',
            'zoho_item_id' => $variant->zoho_item_id,
            'created' => false,
            'updated' => true,
            'zoho_response' => $result,
        ];
    }


    public function syncItem(ProductVariant $variant): array
    {
        /*
    |--------------------------------------------------------------------------
    | 1. Check Zoho's real current state
    |--------------------------------------------------------------------------
    */

        $zohoItem = $this->findItemByShopifyVariantId(
            $variant->shopify_variant_id
        );

        /*
    |--------------------------------------------------------------------------
    | 2. Reconcile local mapping with Zoho
    |--------------------------------------------------------------------------
    */

        if (!$zohoItem) {
            /*
        | Zoho item does not exist anymore.
        |
        | Clear stale local mapping so the next operation
        | treats this variant as a new Zoho item.
        */

            $variant->update([
                'zoho_item_id' => null,
                'zoho_sync_hash' => null,
                'zoho_synced_at' => null,
            ]);
        } else {
            /*
        | Zoho item exists.
        |
        | Make local mapping match the real Zoho item ID.
        */

            $realZohoItemId =
                $zohoItem['item_id'] ?? null;

            if ($realZohoItemId) {
                $variant->update([
                    'zoho_item_id' =>
                    $realZohoItemId,
                ]);
            }
        }

        /*
    |--------------------------------------------------------------------------
    | 3. Calculate current Shopify data hash
    |--------------------------------------------------------------------------
    */

        $currentHash =
            $this->getSyncHash($variant);

        /*
    |--------------------------------------------------------------------------
    | 4. Skip only when the REAL Zoho item exists
    |    and Shopify data hasn't changed.
    |--------------------------------------------------------------------------
    */

        if (
            $zohoItem &&
            $variant->zoho_item_id &&
            $variant->zoho_sync_hash === $currentHash
        ) {
            SyncHistory::create([
                'shop_id' =>
                $this->shop->id,

                'product_variant_id' =>
                $variant->id,

                'action' =>
                'skip',

                'status' =>
                'skipped',

                'zoho_item_id' =>
                $variant->zoho_item_id,

                'message' =>
                'Variant is already synchronized. No changes detected.',

                'synced_at' =>
                now(),
            ]);

            return [
                'message' =>
                'Zoho item is already up to date.',

                'zoho_item_id' =>
                $variant->zoho_item_id,

                'created' =>
                false,

                'updated' =>
                false,

                'skipped' =>
                true,
            ];
        }

        try {
            /*
        |--------------------------------------------------------------------------
        | 5. Create if Zoho item does not exist
        |--------------------------------------------------------------------------
        */

            if (!$variant->zoho_item_id) {
                $result =
                    $this->createItem($variant);

                $variant->update([
                    'zoho_sync_hash' =>
                    $currentHash,

                    'zoho_synced_at' =>
                    now(),
                ]);

                SyncHistory::create([
                    'shop_id' =>
                    $this->shop->id,

                    'product_variant_id' =>
                    $variant->id,

                    'action' =>
                    'create',

                    'status' =>
                    'success',

                    'zoho_item_id' =>
                    $variant->zoho_item_id,

                    'message' =>
                    $result['message']
                        ??
                        'Zoho item created successfully.',

                    'synced_at' =>
                    now(),
                ]);

                return $result;
            }

            /*
        |--------------------------------------------------------------------------
        | 6. Update existing Zoho item
        |--------------------------------------------------------------------------
        */

            $result =
                $this->updateItem($variant);

            $variant->update([
                'zoho_sync_hash' =>
                $currentHash,

                'zoho_synced_at' =>
                now(),
            ]);

            SyncHistory::create([
                'shop_id' =>
                $this->shop->id,

                'product_variant_id' =>
                $variant->id,

                'action' =>
                'update',

                'status' =>
                'success',

                'zoho_item_id' =>
                $variant->zoho_item_id,

                'message' =>
                $result['message']
                    ??
                    'Zoho item updated successfully.',

                'synced_at' =>
                now(),
            ]);

            return $result;
        } catch (\Throwable $e) {

            SyncHistory::create([
                'shop_id' =>
                $this->shop->id,

                'product_variant_id' =>
                $variant->id,

                'action' =>
                $variant->zoho_item_id
                    ? 'update'
                    : 'create',

                'status' =>
                'failed',

                'zoho_item_id' =>
                $variant->zoho_item_id,

                'message' =>
                $e->getMessage(),

                'synced_at' =>
                now(),
            ]);

            throw $e;
        }
    }

    // Synchronize all Shopify product variants with Zoho Books
    public function syncAllVariants(): array
    {
        // Get all Shopify product variants with their parent products
        $variants = ProductVariant::with('product')->get();

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        // Synchronize every Shopify variant with Zoho
        foreach ($variants as $variant) {
            try {
                // Create a new item or update the existing item
                $result = $this->syncItem($variant);

                // Count newly created items
                if ($result['created'] ?? false) {
                    $created++;
                }

                // Count updated items
                elseif ($result['updated'] ?? false) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                // Continue processing other variants if one fails
                $failed++;

                $errors[] = [
                    'variant_id' => $variant->id,
                    'title' => $variant->title,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Return a small synchronization summary
        return [
            'total_processed' => $variants->count(),
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }


    private function getSyncHash(ProductVariant $variant): string
    {
        return hash('sha256', json_encode([
            'title' => $variant->title,
            'sku' => $variant->sku,
            'price' => $variant->price,
        ]));
    }
}
