<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Models\SyncHistory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $accountsUrl = ZohoDatacenter::validateAccountsUrl($connection->accounts_url);

        if (!$accountsUrl) {
            throw new \RuntimeException('Zoho connection is missing or has an invalid accounts_url endpoint configuration.');
        }

        $clientId = config('services.zoho.client_id') ?: env('ZOHO_CLIENT_ID');
        $clientSecret = config('services.zoho.client_secret') ?: env('ZOHO_CLIENT_SECRET');

        $response = Http::asForm()->post(
            $accountsUrl . '/oauth/v2/token',
            [
                'refresh_token' => $connection->refresh_token,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
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

        $apiUrl = ZohoDatacenter::validateApiUrl($connection->api_url);

        if (!$apiUrl) {
            throw new \RuntimeException('Zoho connection is missing or has an invalid api_url endpoint configuration.');
        }

        $token = $this->getAccessToken();

        $url = rtrim($connection->api_url, '/') . $endpoint;

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
            $responseData = $response->json();
            $code = $responseData['code'] ?? 0;
            throw new \Exception(
                'Zoho API request failed: ' . $response->body(),
                is_numeric($code) ? (int) $code : 0
            );
        }

        $responseData = $response->json();

        if (isset($responseData['code']) && (int) $responseData['code'] !== 0) {
            throw new \Exception(
                'Zoho API error (' . $responseData['code'] . '): ' . ($responseData['message'] ?? 'Unknown error'),
                (int) $responseData['code']
            );
        }

        return $responseData;
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

    /**
     * Fetch single item from Zoho Books by item ID. Returns null if item is not found (code 1002).
     */
    public function getItem(string $zohoItemId): ?array
    {
        try {
            $response = $this->makeRequest('GET', '/books/v3/items/' . $zohoItemId);
            return $response['item'] ?? null;
        } catch (\Throwable $e) {
            if ($this->isItemNotFoundException($e)) {
                return null;
            }
            throw $e;
        }
    }

    public function isItemNotFoundResponse(array $response): bool
    {
        $code = (int) ($response['code'] ?? 0);
        return $code === 1002 || $code === 5;
    }

    public function isItemNotFoundException(\Throwable $e): bool
    {
        $code = $e->getCode();
        if ($code === 1002 || $code === 5) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, '"code":1002') ||
            str_contains($message, '"code": 1002') ||
            str_contains($message, '"code":5') ||
            str_contains($message, '"code": 5') ||
            str_contains($message, 'code 1002') ||
            str_contains($message, 'code 5') ||
            str_contains($message, 'not available') ||
            str_contains($message, 'couldnt find any resource') ||
            str_contains($message, 'could not find any resource');
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

    /**
     * Find existing Zoho item by SKU.
     */
    public function findItemBySku(string $sku): ?array
    {
        $trimmedSku = trim($sku);
        if ($trimmedSku === '') {
            return null;
        }

        // 1. Try querying Zoho API with sku filter
        try {
            $response = $this->makeRequest('GET', '/books/v3/items', [
                'sku' => $trimmedSku,
            ]);

            $items = $response['items'] ?? [];
            foreach ($items as $item) {
                if (!empty($item['sku']) && strcasecmp(trim($item['sku']), $trimmedSku) === 0) {
                    return $item;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Zoho API SKU search filter failed for SKU '{$trimmedSku}': " . $e->getMessage());
        }

        // 2. Fallback search across items list
        try {
            $items = $this->getItems();
            foreach ($items as $item) {
                if (!empty($item['sku']) && strcasecmp(trim($item['sku']), $trimmedSku) === 0) {
                    return $item;
                }
            }
        } catch (\Throwable $e) {
            Log::error("Zoho getItems fallback SKU search failed for SKU '{$trimmedSku}': " . $e->getMessage());
            throw $e;
        }

        return null;
    }


    // Create a Zoho Books item from a Shopify product variant
    public function createItem(ProductVariant $variant): array {
        // Prevent duplicate Zoho item creation if variant already has zoho_item_id
        if ($variant->zoho_item_id) {
            return [
                'message' => 'Zoho item already exists for this variant.',
                'zoho_item_id' => $variant->zoho_item_id,
                'created' => false,
                'updated' => false,
            ];
        }

        // Duplicate protection: check if an item with this Shopify Variant ID already exists in Zoho
        $existingInZoho = $this->findItemByShopifyVariantId($variant->shopify_variant_id);
        if ($existingInZoho && !empty($existingInZoho['item_id'])) {
            $zohoItemId = (string) $existingInZoho['item_id'];
            Log::info("createItem: Found existing Zoho item {$zohoItemId} by custom field cf_shopify_variant_id for variant ID {$variant->id}.");
            $variant->update([
                'zoho_item_id' => $zohoItemId,
            ]);

            return $this->updateItem($variant);
        }

        // Duplicate protection by SKU: check if an item with this SKU already exists in Zoho
        if (!empty(trim($variant->sku ?? ''))) {
            try {
                $skuMatch = $this->findItemBySku($variant->sku);
                if ($skuMatch && !empty($skuMatch['item_id'])) {
                    $matchedZohoId = (string) $skuMatch['item_id'];

                    $alreadyLinked = ProductVariant::where('zoho_item_id', $matchedZohoId)
                        ->where('id', '!=', $variant->id)
                        ->exists();

                    if ($alreadyLinked) {
                        Log::warning("createItem: Zoho item {$matchedZohoId} matches SKU '{$variant->sku}', but is already linked to another local variant. Proceeding with new item creation.");
                    } else {
                        Log::info("createItem: Found existing Zoho item {$matchedZohoId} matching SKU '{$variant->sku}' for variant ID {$variant->id}. Linking existing item.");
                        $variant->update([
                            'zoho_item_id' => $matchedZohoId,
                        ]);
                        return $this->updateItem($variant);
                    }
                } else {
                    Log::info("createItem: No matching Zoho item found by SKU '{$variant->sku}' for variant ID {$variant->id}. Creating new Zoho item.");
                }
            } catch (\Throwable $e) {
                Log::error("createItem: Zoho SKU lookup failed for SKU '{$variant->sku}' on variant ID {$variant->id}: " . $e->getMessage());
            }
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
            'zoho_item_id' => (string) $zohoItemId,
        ]);

        // Return sync information along with the Zoho response
        return [
            'message' => 'Zoho item created successfully.',
            'zoho_item_id' => (string) $zohoItemId,
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

        // Include custom field for Shopify Variant ID if configured
        try {
            $shopifyVariantFieldId = $this->getShopifyVariantFieldId();
            if ($shopifyVariantFieldId) {
                $data['custom_fields'] = [
                    [
                        'customfield_id' => $shopifyVariantFieldId,
                        'value' => $variant->shopify_variant_id,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("Could not append custom field to updateItem for variant ID {$variant->id}: " . $e->getMessage());
        }

        // Include SKU only when Shopify has one
        if (!empty($variant->sku)) {
            $data['sku'] = $variant->sku;
        }

        try {
            // Update the existing item in Zoho Books
            $result = $this->makeRequest(
                'PUT',
                '/books/v3/items/' . $variant->zoho_item_id,
                $data
            );
        } catch (\Throwable $e) {
            if ($this->isItemNotFoundException($e)) {
                // Code 1002 detected: clear stale local mapping
                $variant->update([
                    'zoho_item_id' => null,
                    'zoho_sync_hash' => null,
                    'zoho_synced_at' => null,
                ]);

                // Reconcile: find existing item by cf_shopify_variant_id or create new
                $existingInZoho = $this->findItemByShopifyVariantId($variant->shopify_variant_id);
                if ($existingInZoho && !empty($existingInZoho['item_id'])) {
                    $variant->update([
                        'zoho_item_id' => (string) $existingInZoho['item_id'],
                    ]);
                    return $this->updateItem($variant);
                }

                return $this->createItem($variant);
            }

            throw $e;
        }

        // Upload product featured image if available (failure does not rollback item update)
        if (!empty($product->image_url)) {
            try {
                $this->uploadItemImage($variant, $product->image_url);
            } catch (\Throwable $e) {
                Log::error("Zoho image upload failed during updateItem for variant {$variant->id}: " . $e->getMessage());
            }
        }

        // Return sync information along with the Zoho response
        return [
            'message' => 'Zoho item updated successfully.',
            'zoho_item_id' => $variant->zoho_item_id,
            'created' => false,
            'updated' => true,
            'zoho_response' => $result,
        ];
    }

    /**
     * Dedicated method for uploading/replacing a Zoho item's image via multipart/form-data.
     */
    public function uploadItemImage(ProductVariant $variant, ?string $imageUrl = null): array
    {
        $zohoItemId = $variant->zoho_item_id;

        if (!$zohoItemId) {
            return [
                'success' => false,
                'message' => 'Variant is not linked to a Zoho item.',
            ];
        }

        $url = $imageUrl ?? $variant->product?->image_url;

        if (empty($url)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'No image URL provided or found on product.',
            ];
        }

        try {
            // Download the image bytes
            $imageResponse = Http::timeout(10)->get($url);

            if (!$imageResponse->successful()) {
                Log::warning("Failed to download image from {$url} for Zoho item {$zohoItemId}");
                return [
                    'success' => false,
                    'message' => 'Failed to download image from URL.',
                ];
            }

            $imageBytes = $imageResponse->body();
            $filename = basename(parse_url($url, PHP_URL_PATH) ?? 'image.jpg') ?: 'image.jpg';

            $connection = $this->getConnection();
            $apiUrl = ZohoDatacenter::validateApiUrl($connection->api_url);

            if (!$apiUrl) {
                throw new \RuntimeException('Zoho connection has an invalid api_url endpoint configuration.');
            }

            $token = $this->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Zoho-oauthtoken ' . $token,
            ])->attach(
                'image',
                $imageBytes,
                $filename
            )->post(
                $apiUrl . '/books/v3/items/' . $zohoItemId . '/image',
                [
                    'organization_id' => $connection->organization_id,
                ]
            );

            if (!$response->successful()) {
                Log::warning("Zoho image upload failed for item {$zohoItemId}: " . $response->body());
                return [
                    'success' => false,
                    'message' => 'Zoho image upload API call failed.',
                    'response' => $response->json(),
                ];
            }

            return [
                'success' => true,
                'uploaded' => true,
                'zoho_response' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error("Zoho image upload exception for item {$zohoItemId}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }


    public function syncItem(ProductVariant $variant): array
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Reconcile local mapping with Zoho
        |--------------------------------------------------------------------------
        */

        // If local variant has a mapped zoho_item_id, verify whether it still exists in Zoho
        if ($variant->zoho_item_id) {
            $existingItem = $this->getItem($variant->zoho_item_id);

            if (!$existingItem) {
                // Item 1002 Not Found in Zoho: clear stale local mapping
                $variant->update([
                    'zoho_item_id' => null,
                    'zoho_sync_hash' => null,
                    'zoho_synced_at' => null,
                ]);
            }
        }

        // Search Zoho by custom field cf_shopify_variant_id if local mapping is missing or was cleared
        $zohoItem = $this->findItemByShopifyVariantId($variant->shopify_variant_id);

        if ($zohoItem && !empty($zohoItem['item_id'])) {
            Log::info("syncItem: Found matching Zoho item {$zohoItem['item_id']} by custom field cf_shopify_variant_id for variant ID {$variant->id}.");
            $variant->update([
                'zoho_item_id' => (string) $zohoItem['item_id'],
            ]);
        }

        // Search Zoho by SKU if local mapping is still missing and variant has a non-empty SKU
        if (!$variant->zoho_item_id && !empty(trim($variant->sku ?? ''))) {
            try {
                $skuMatch = $this->findItemBySku($variant->sku);
                if ($skuMatch && !empty($skuMatch['item_id'])) {
                    $matchedZohoId = (string) $skuMatch['item_id'];

                    $alreadyLinked = ProductVariant::where('zoho_item_id', $matchedZohoId)
                        ->where('id', '!=', $variant->id)
                        ->exists();

                    if ($alreadyLinked) {
                        Log::warning("syncItem: Found Zoho item {$matchedZohoId} matching SKU '{$variant->sku}', but it is already linked to another local variant. Skipping SKU linking for variant ID {$variant->id}.");
                    } else {
                        Log::info("syncItem: Found matching Zoho item {$matchedZohoId} by SKU '{$variant->sku}' for variant ID {$variant->id}. Linking existing Zoho item.");
                        $variant->update([
                            'zoho_item_id' => $matchedZohoId,
                        ]);
                        $zohoItem = $skuMatch;
                    }
                } else {
                    Log::info("syncItem: No matching Zoho item found by SKU '{$variant->sku}' for variant ID {$variant->id}. Proceeding with new item creation.");
                }
            } catch (\Throwable $e) {
                Log::error("syncItem: Zoho SKU lookup failed for SKU '{$variant->sku}' on variant ID {$variant->id}: " . $e->getMessage());
            }
}

        /*
    |--------------------------------------------------------------------------
    | 3. Calculate current Shopify data hash
    |--------------------------------------------------------------------------
    */

        $currentHash = $this->getSyncHash($variant);

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


    /**
     * Synchronize inventory quantity from Shopify to Zoho Books / Inventory.
     */
    public function syncInventory(ProductVariant $variant, ?int $newShopifyQuantity = null): array {
        if (!$variant->zoho_item_id) {
            Log::warning("syncInventory: Variant ID {$variant->id} is not linked to a Zoho item. Skipping inventory sync.");
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Variant is not linked to a Zoho item.',
            ];
        }

        $targetQuantity = $newShopifyQuantity ?? (int) $variant->inventory_quantity;

        $zohoItem = $this->getItem($variant->zoho_item_id);

        if (!$zohoItem) {
            Log::error("syncInventory: Zoho item ID {$variant->zoho_item_id} not found for variant ID {$variant->id}.");
            return [
                'success' => false,
                'message' => "Zoho item ID {$variant->zoho_item_id} not found.",
            ];
        }

        $currentZohoQuantity = (int) (
            $zohoItem['actual_available_stock'] ??
            $zohoItem['stock_on_hand'] ??
            $zohoItem['available_stock'] ??
            0
        );

        $delta = $targetQuantity - $currentZohoQuantity;

        Log::info("syncInventory: Variant ID {$variant->id} (Zoho Item: {$variant->zoho_item_id}): Shopify Qty = {$targetQuantity}, Zoho Qty = {$currentZohoQuantity}, Delta = {$delta}");

        if ($delta === 0) {
            Log::info("syncInventory: Stock is already in sync ({$targetQuantity}) for variant ID {$variant->id}. No adjustment needed.");
            return [
                'success' => true,
                'adjusted' => false,
                'shopify_quantity' => $targetQuantity,
                'zoho_quantity' => $currentZohoQuantity,
                'delta' => 0,
                'message' => 'Inventory is already in sync.',
            ];
        }

        $payload = [
            'date' => now()->format('Y-m-d'),
            'reason' => 'Shopify Inventory Sync',
            'adjustment_type' => 'quantity',
            'line_items' => [
                [
                    'item_id' => $variant->zoho_item_id,
                    'quantity_adjusted' => $delta,
                ],
            ],
        ];

        try {
            $response = $this->makeRequest('POST', '/books/v3/inventoryadjustments', $payload);

            Log::info("syncInventory: Successfully created Zoho inventory adjustment for variant ID {$variant->id}. Delta: {$delta}");

            return [
                'success' => true,
                'adjusted' => true,
                'shopify_quantity' => $targetQuantity,
                'zoho_quantity' => $currentZohoQuantity,
                'delta' => $delta,
                'zoho_response' => $response,
                'message' => 'Inventory adjustment created successfully.',
            ];
        } catch (\Throwable $e) {
            Log::error("syncInventory: Failed to create Zoho inventory adjustment for variant ID {$variant->id} (Delta: {$delta}): " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Synchronize inventory quantity from Zoho Books back to Shopify.
     * Prevents overselling by clamping stock to non-negative quantities.
     */
    public function syncZohoInventoryToShopify(ProductVariant $variant, ?string $locationId = null): array {
        if (!$variant->zoho_item_id) {
            Log::warning("syncZohoInventoryToShopify: Variant ID {$variant->id} is not linked to a Zoho item.");
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Variant is not linked to a Zoho item.',
            ];
        }

        if (!$variant->shopify_inventory_item_id) {
            Log::warning("syncZohoInventoryToShopify: Variant ID {$variant->id} does not have a mapped shopify_inventory_item_id.");
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Variant does not have a mapped Shopify inventory item ID.',
            ];
        }

        $zohoItem = $this->getItem($variant->zoho_item_id);

        if (!$zohoItem) {
            Log::error("syncZohoInventoryToShopify: Zoho item ID {$variant->zoho_item_id} not found for variant ID {$variant->id}.");
            return [
                'success' => false,
                'message' => "Zoho item ID {$variant->zoho_item_id} not found.",
            ];
        }

        $zohoQuantity = (int) (
            $zohoItem['actual_available_stock'] ??
            $zohoItem['stock_on_hand'] ??
            $zohoItem['available_stock'] ??
            0
        );

        $safeQuantity = max(0, $zohoQuantity);

        $shopifyService = app(ShopifyService::class);
        $result = $shopifyService->setInventoryQuantity(
            $this->shop,
            $variant->shopify_inventory_item_id,
            $safeQuantity,
            $locationId
        );

        $variant->inventory_quantity = $safeQuantity;
        $variant->save();

        Log::info("syncZohoInventoryToShopify: Updated variant ID {$variant->id} inventory to {$safeQuantity} on Shopify (Zoho stock: {$zohoQuantity}).");

        return [
            'success' => true,
            'variant_id' => $variant->id,
            'zoho_quantity' => $zohoQuantity,
            'shopify_quantity' => $safeQuantity,
            'shopify_response' => $result,
            'message' => 'Inventory synchronized from Zoho to Shopify successfully.',
        ];
    }

    /**
     * Find existing Zoho contact by email address.
     */
    public function findZohoContactByEmail(string $email): ?array
    {
        $trimmedEmail = trim($email);
        if ($trimmedEmail === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', '/books/v3/contacts', [
                'email' => $trimmedEmail,
            ]);

            $contacts = $response['contacts'] ?? [];
            foreach ($contacts as $contact) {
                if (!empty($contact['email']) && strcasecmp(trim($contact['email']), $trimmedEmail) === 0) {
                    return $contact;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("findZohoContactByEmail failed for email '{$trimmedEmail}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Find existing Zoho contact by contact name.
     */
    public function findZohoContactByName(string $name): ?array
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', '/books/v3/contacts', [
                'contact_name' => $trimmedName,
            ]);

            $contacts = $response['contacts'] ?? [];
            foreach ($contacts as $contact) {
                if (!empty($contact['contact_name']) && strcasecmp(trim($contact['contact_name']), $trimmedName) === 0) {
                    return $contact;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("findZohoContactByName failed for name '{$trimmedName}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fetch single contact from Zoho Books by contact ID.
     */
    public function getContact(string $contactId): ?array
    {
        try {
            $response = $this->makeRequest('GET', '/books/v3/contacts/' . $contactId);
            return $response['contact'] ?? null;
        } catch (\Throwable $e) {
            if ($this->isItemNotFoundException($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create a new contact in Zoho Books.
     */
    public function createContact(array $payload): array
    {
        return $this->makeRequest('POST', '/books/v3/contacts', $payload);
    }

    /**
     * Update an existing contact in Zoho Books.
     */
    public function updateContact(string $contactId, array $payload): array
    {
        return $this->makeRequest('PUT', '/books/v3/contacts/' . $contactId, $payload);
    }

    /**
     * Synchronize a Shopify customer to Zoho Books as a customer contact.
     */
    public function syncCustomer(Customer $customer): array
    {
        $fullName = $customer->full_name;

        $payload = [
            'contact_name' => $fullName,
            'contact_type' => 'customer',
            'contact_persons' => [
                [
                    'first_name' => $customer->first_name ?? '',
                    'last_name' => $customer->last_name ?? '',
                    'email' => $customer->email ?? '',
                    'phone' => $customer->phone ?? '',
                    'is_primary_contact' => true,
                ],
            ],
        ];

        $billing = $customer->billing_address;
        if (!empty($billing) && is_array($billing)) {
            $payload['billing_address'] = [
                'address' => $billing['address1'] ?? '',
                'street2' => $billing['address2'] ?? '',
                'city' => $billing['city'] ?? '',
                'state' => $billing['province'] ?? $billing['province_code'] ?? '',
                'zip' => $billing['zip'] ?? '',
                'country' => $billing['country'] ?? '',
                'phone' => $billing['phone'] ?? $customer->phone ?? '',
            ];
            if (!empty($billing['company'])) {
                $payload['company_name'] = $billing['company'];
            }
        }

        $shipping = $customer->shipping_address;
        if (!empty($shipping) && is_array($shipping)) {
            $payload['shipping_address'] = [
                'address' => $shipping['address1'] ?? '',
                'street2' => $shipping['address2'] ?? '',
                'city' => $shipping['city'] ?? '',
                'state' => $shipping['province'] ?? $shipping['province_code'] ?? '',
                'zip' => $shipping['zip'] ?? '',
                'country' => $shipping['country'] ?? '',
                'phone' => $shipping['phone'] ?? $customer->phone ?? '',
            ];
            if (empty($payload['company_name']) && !empty($shipping['company'])) {
                $payload['company_name'] = $shipping['company'];
            }
        }

        $zohoContactId = $customer->zoho_contact_id;
        $created = false;
        $updated = false;

        // 1. If not linked, search Zoho for existing contact by email or name
        if (!$zohoContactId) {
            $existing = null;
            if (!empty($customer->email)) {
                $existing = $this->findZohoContactByEmail($customer->email);
            }
            if (!$existing && !empty($fullName)) {
                $existing = $this->findZohoContactByName($fullName);
            }

            if ($existing) {
                $zohoContactId = (string) $existing['contact_id'];
                $customer->zoho_contact_id = $zohoContactId;
                Log::info("syncCustomer: Mapped existing Zoho contact ID {$zohoContactId} for customer ID {$customer->id}.");
            }
        }

        // 2. Update existing contact or create a new one
        if ($zohoContactId) {
            try {
                $response = $this->updateContact($zohoContactId, $payload);
                $updated = true;
                Log::info("syncCustomer: Updated Zoho contact ID {$zohoContactId} for customer ID {$customer->id}.");
            } catch (\Throwable $e) {
                if ($this->isItemNotFoundException($e)) {
                    Log::warning("syncCustomer: Zoho contact ID {$zohoContactId} not found on update. Re-creating contact.");
                    $zohoContactId = null;
                    $customer->zoho_contact_id = null;
                } else {
                    throw $e;
                }
            }
        }

        if (!$zohoContactId) {
            $response = $this->createContact($payload);
            $newContact = $response['contact'] ?? [];
            $zohoContactId = (string) ($newContact['contact_id'] ?? null);

            if (!$zohoContactId) {
                throw new \Exception("Zoho did not return a contact_id when creating customer.");
            }

            $customer->zoho_contact_id = $zohoContactId;
            $created = true;
            Log::info("syncCustomer: Created new Zoho contact ID {$zohoContactId} for customer ID {$customer->id}.");
        }

        $hash = hash('sha256', json_encode([
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'billing' => $customer->billing_address,
            'shipping' => $customer->shipping_address,
        ]));

        $customer->zoho_sync_hash = $hash;
        $customer->zoho_synced_at = now();
        $customer->save();

        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'action' => $created ? 'created' : ($updated ? 'updated' : 'synced'),
            'status' => 'success',
            'zoho_item_id' => $zohoContactId,
            'message' => $created ? 'Customer created in Zoho Books.' : 'Customer updated in Zoho Books.',
            'synced_at' => now(),
        ]);

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'zoho_contact_id' => $zohoContactId,
            'customer_id' => $customer->id,
            'message' => $created ? 'Customer created successfully.' : 'Customer updated successfully.',
        ];
    }

    /**
     * Find existing Zoho Item by SKU.
     */
    public function findZohoItemBySku(string $sku): ?array
    {
        $trimmedSku = trim($sku);
        if ($trimmedSku === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', '/books/v3/items', [
                'sku' => $trimmedSku,
            ]);

            $items = $response['items'] ?? [];
            foreach ($items as $item) {
                if (!empty($item['sku']) && strcasecmp(trim($item['sku']), $trimmedSku) === 0) {
                    return $item;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("findZohoItemBySku failed for SKU '{$trimmedSku}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Find existing Zoho Sales Order by reference number.
     */
    public function findZohoSalesOrderByReferenceNumber(string $refNumber): ?array
    {
        $trimmedRef = trim($refNumber);
        if ($trimmedRef === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', '/books/v3/salesorders', [
                'reference_number' => $trimmedRef,
            ]);

            $salesOrders = $response['salesorders'] ?? [];
            foreach ($salesOrders as $so) {
                if (!empty($so['reference_number']) && strcasecmp(trim($so['reference_number']), $trimmedRef) === 0) {
                    return $so;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("findZohoSalesOrderByReferenceNumber failed for ref '{$trimmedRef}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Get single Sales Order by Sales Order ID.
     */
    public function getSalesOrder(string $salesOrderId): ?array
    {
        try {
            $response = $this->makeRequest('GET', '/books/v3/salesorders/' . $salesOrderId);
            return $response['salesorder'] ?? null;
        } catch (\Throwable $e) {
            if ($this->isItemNotFoundException($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create a new Sales Order in Zoho Books.
     */
    public function createSalesOrder(array $payload): array
    {
        return $this->makeRequest('POST', '/books/v3/salesorders', $payload);
    }

    /**
     * Update an existing Sales Order in Zoho Books.
     */
    public function updateSalesOrder(string $salesOrderId, array $payload): array
    {
        return $this->makeRequest('PUT', '/books/v3/salesorders/' . $salesOrderId, $payload);
    }

    /**
     * Synchronize a Shopify order to Zoho Books as a Sales Order.
     */
    public function syncOrder(Order $order): array
    {
        // 1. Resolve customer
        $zohoContactId = null;
        if ($order->customer_id) {
            $customer = Customer::find($order->customer_id);
            if ($customer) {
                if (!$customer->zoho_contact_id) {
                    $this->syncCustomer($customer);
                    $customer->refresh();
                }
                $zohoContactId = $customer->zoho_contact_id;
            }
        }

        if (!$zohoContactId) {
            throw new \Exception("Cannot sync order ID {$order->id}: Customer is missing or not mapped to Zoho contact.");
        }

        // 2. Map line items
        $lineItems = $order->line_items ?? [];
        if (empty($lineItems)) {
            throw new \Exception("Cannot sync order ID {$order->id}: Order has no line items.");
        }

        $zohoLineItems = [];
        foreach ($lineItems as $index => $item) {
            $shopifyVariantId = $item['variant_id'] ?? null;
            $sku = $item['sku'] ?? null;
            $name = $item['name'] ?? $item['title'] ?? "Item " . ($index + 1);
            $qty = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? $item['unit_price'] ?? 0.00);

            $zohoItemId = null;

            // Preferred lookup 1: ProductVariant by shopify_variant_id
            if ($shopifyVariantId) {
                $formattedVariantId = str_starts_with((string) $shopifyVariantId, 'gid://')
                    ? (string) $shopifyVariantId
                    : "gid://shopify/ProductVariant/{$shopifyVariantId}";

                $variant = ProductVariant::where('shopify_variant_id', $formattedVariantId)->first();
                if ($variant && $variant->zoho_item_id) {
                    $zohoItemId = $variant->zoho_item_id;
                }
            }

            // Preferred lookup 2: ProductVariant by SKU
            if (!$zohoItemId && !empty($sku)) {
                $variant = ProductVariant::where('sku', $sku)->first();
                if ($variant && $variant->zoho_item_id) {
                    $zohoItemId = $variant->zoho_item_id;
                }
            }

            // Preferred lookup 3: Zoho Item API search by SKU
            if (!$zohoItemId && !empty($sku)) {
                $zohoItem = $this->findZohoItemBySku($sku);
                if ($zohoItem && !empty($zohoItem['item_id'])) {
                    $zohoItemId = (string) $zohoItem['item_id'];
                }
            }

            if (!$zohoItemId) {
                throw new \Exception("Cannot sync order ID {$order->id}: Unmapped Shopify product variant/SKU '{$sku}' for item '{$name}'.");
            }

            $lineItemPayload = [
                'item_id' => $zohoItemId,
                'name' => $name,
                'description' => 'SKU: ' . ($sku ?? 'N/A'),
                'rate' => $price,
                'quantity' => $qty,
            ];

            if (!empty($item['total_discount']) && (float) $item['total_discount'] > 0) {
                $lineItemPayload['discount'] = (float) $item['total_discount'];
            }

            $zohoLineItems[] = $lineItemPayload;
        }

        // 3. Build Sales Order Payload
        $refNumber = $order->order_number ?? (string) $order->shopify_order_id;
        $orderDateStr = $order->order_date ? $order->order_date->format('Y-m-d') : date('Y-m-d');

        $payload = [
            'customer_id' => $zohoContactId,
            'reference_number' => $refNumber,
            'date' => $orderDateStr,
            'line_items' => $zohoLineItems,
        ];

        if (!empty($order->currency)) {
            $payload['currency_code'] = strtoupper($order->currency);
        }

        if ((float) $order->discount_total > 0) {
            $payload['discount'] = (float) $order->discount_total;
            $payload['is_discount_before_tax'] = true;
        }

        if ((float) $order->shipping_total > 0) {
            $payload['shipping_charge'] = (float) $order->shipping_total;
        }

        if ((float) $order->tax_total > 0) {
            $payload['tax_total'] = (float) $order->tax_total;
        }

        $notesArray = [];
        if (!empty($order->notes)) {
            $notesArray[] = "Order Note: " . $order->notes;
        }
        if (!empty($order->coupon_code)) {
            $notesArray[] = "Coupon Code: " . $order->coupon_code;
        }
        if (!empty($notesArray)) {
            $payload['notes'] = implode("\n", $notesArray);
        }

        $zohoSalesOrderId = $order->zoho_sales_order_id;
        $created = false;
        $updated = false;

        // 4. If not linked, check Zoho for existing Sales Order by reference number
        if (!$zohoSalesOrderId) {
            $existing = $this->findZohoSalesOrderByReferenceNumber($refNumber);
            if ($existing && !empty($existing['salesorder_id'])) {
                $zohoSalesOrderId = (string) $existing['salesorder_id'];
                $order->zoho_sales_order_id = $zohoSalesOrderId;
                $order->zoho_sales_order_number = $existing['salesorder_number'] ?? null;
                Log::info("syncOrder: Mapped existing Zoho Sales Order ID {$zohoSalesOrderId} for order ID {$order->id}.");
            }
        }

        // 5. Update existing Sales Order or create a new one
        if ($zohoSalesOrderId) {
            try {
                $response = $this->updateSalesOrder($zohoSalesOrderId, $payload);
                $so = $response['salesorder'] ?? [];
                if (!empty($so['salesorder_number'])) {
                    $order->zoho_sales_order_number = $so['salesorder_number'];
                }
                $updated = true;
                Log::info("syncOrder: Updated Zoho Sales Order ID {$zohoSalesOrderId} for order ID {$order->id}.");
            } catch (\Throwable $e) {
                if ($this->isItemNotFoundException($e)) {
                    Log::warning("syncOrder: Zoho Sales Order ID {$zohoSalesOrderId} not found on update. Re-creating Sales Order.");
                    $zohoSalesOrderId = null;
                    $order->zoho_sales_order_id = null;
                } else {
                    throw $e;
                }
            }
        }

        if (!$zohoSalesOrderId) {
            $response = $this->createSalesOrder($payload);
            $so = $response['salesorder'] ?? [];
            $zohoSalesOrderId = (string) ($so['salesorder_id'] ?? null);

            if (!$zohoSalesOrderId) {
                throw new \Exception("Zoho did not return a salesorder_id when creating Sales Order.");
            }

            $order->zoho_sales_order_id = $zohoSalesOrderId;
            $order->zoho_sales_order_number = $so['salesorder_number'] ?? null;
            $created = true;
            Log::info("syncOrder: Created new Zoho Sales Order ID {$zohoSalesOrderId} for order ID {$order->id}.");
        }

        $hash = hash('sha256', json_encode([
            'order_number' => $order->order_number,
            'subtotal' => $order->subtotal,
            'discount' => $order->discount_total,
            'shipping' => $order->shipping_total,
            'tax' => $order->tax_total,
            'total' => $order->total_price,
            'line_items' => $order->line_items,
        ]));

        $order->zoho_sync_hash = $hash;
        $order->zoho_synced_at = now();
        $order->save();

        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'action' => $created ? 'created' : ($updated ? 'updated' : 'synced'),
            'status' => 'success',
            'zoho_item_id' => $zohoSalesOrderId,
            'message' => $created ? 'Sales Order created in Zoho Books.' : 'Sales Order updated in Zoho Books.',
            'synced_at' => now(),
        ]);

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'zoho_sales_order_id' => $zohoSalesOrderId,
            'zoho_sales_order_number' => $order->zoho_sales_order_number,
            'order_id' => $order->id,
            'message' => $created ? 'Sales Order created successfully.' : 'Sales Order updated successfully.',
        ];
    }

    /**
     * Find existing Zoho Invoice by reference number.
     */
    public function findZohoInvoiceByReferenceNumber(string $refNumber): ?array
    {
        $trimmedRef = trim($refNumber);
        if ($trimmedRef === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', '/books/v3/invoices', [
                'reference_number' => $trimmedRef,
            ]);

            $invoices = $response['invoices'] ?? [];
            foreach ($invoices as $inv) {
                if (!empty($inv['reference_number']) && trim($inv['reference_number']) === $trimmedRef) {
                    return $inv;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("findZohoInvoiceByReferenceNumber failed for ref '{$trimmedRef}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Get a specific Zoho Invoice by ID.
     */
    public function getInvoice(string $zohoInvoiceId): array
    {
        $response = $this->makeRequest('GET', "/books/v3/invoices/{$zohoInvoiceId}");
        return $response['invoice'] ?? [];
    }

    /**
     * Create a new Zoho Invoice.
     */
    public function createInvoice(Order $order, array $payload): array
    {
        $response = $this->makeRequest('POST', '/books/v3/invoices', $payload);
        return $response['invoice'] ?? [];
    }

    /**
     * Update an existing Zoho Invoice.
     */
    public function updateInvoice(Invoice $invoice, Order $order, array $payload): array
    {
        $response = $this->makeRequest('PUT', "/books/v3/invoices/{$invoice->zoho_invoice_id}", $payload);
        return $response['invoice'] ?? [];
    }

    /**
     * Synchronize a Shopify Order as a Zoho Invoice.
     */
    public function syncInvoice(Order $order): array
    {
        if ($order->shop_id !== $this->shop->id) {
            throw new \Exception("Order #{$order->id} does not belong to shop {$this->shop->shop_domain}");
        }

        // 1. Resolve & Sync Customer
        $customer = $order->customer;
        if (!$customer && $order->customer_id) {
            $customer = Customer::find($order->customer_id);
        }

        if (!$customer) {
            throw new \Exception("Cannot sync invoice for order #{$order->order_number}: No customer associated.");
        }

        if (empty($customer->zoho_contact_id)) {
            $custResult = $this->syncCustomer($customer);
            $customer->refresh();
        }

        if (empty($customer->zoho_contact_id)) {
            throw new \Exception("Cannot sync invoice for order #{$order->order_number}: Customer sync to Zoho failed.");
        }

        // 2. Resolve Line Items
        $mappedLineItems = [];
        $rawLineItems = $order->line_items ?? [];

        foreach ($rawLineItems as $item) {
            $shopifyVariantId = $item['variant_id'] ?? null;
            $sku = $item['sku'] ?? null;
            $zohoItemId = null;

            if ($shopifyVariantId) {
                $formattedVariantId = str_starts_with((string)$shopifyVariantId, 'gid://')
                    ? $shopifyVariantId
                    : "gid://shopify/ProductVariant/{$shopifyVariantId}";

                $variant = ProductVariant::where('shopify_variant_id', $formattedVariantId)
                    ->whereHas('product', function ($q) {
                        $q->where('shop_id', $this->shop->id);
                    })
                    ->first();

                if ($variant && !empty($variant->zoho_item_id)) {
                    $zohoItemId = $variant->zoho_item_id;
                }
            }

            if (!$zohoItemId && !empty($sku)) {
                $variantBySku = ProductVariant::where('sku', $sku)
                    ->whereHas('product', function ($q) {
                        $q->where('shop_id', $this->shop->id);
                    })
                    ->first();

                if ($variantBySku && !empty($variantBySku->zoho_item_id)) {
                    $zohoItemId = $variantBySku->zoho_item_id;
                } else {
                    $zohoItem = $this->findZohoItemBySku($sku);
                    if ($zohoItem && !empty($zohoItem['item_id'])) {
                        $zohoItemId = $zohoItem['item_id'];
                    }
                }
            }

            if (!$zohoItemId) {
                $identifier = $sku ? "SKU '{$sku}'" : "variant ID '{$shopifyVariantId}'";
                $errMsg = "Unmapped Shopify product variant/SKU '{$sku}' for order #{$order->order_number}. Invoice creation aborted.";
                
                SyncHistory::create([
                    'shop_id' => $this->shop->id,
                    'order_id' => $order->id,
                    'action' => 'create',
                    'status' => 'failed',
                    'message' => $errMsg,
                    'synced_at' => now(),
                ]);

                throw new \Exception($errMsg);
            }

            $mappedLineItems[] = [
                'item_id' => $zohoItemId,
                'name' => $item['name'] ?? $item['title'] ?? 'Item',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'rate' => (float) ($item['price'] ?? 0.00),
                'discount' => (float) ($item['total_discount'] ?? 0.00),
            ];
        }

        // 3. Construct Invoice Payload
        $invoicePayload = [
            'customer_id' => $customer->zoho_contact_id,
            'reference_number' => $order->order_number,
            'date' => $order->order_date ? $order->order_date->format('Y-m-d') : date('Y-m-d'),
            'currency_code' => strtoupper($order->currency ?? 'USD'),
            'line_items' => $mappedLineItems,
            'shipping_charge' => (float) ($order->shipping_total ?? 0.00),
            'discount' => (float) ($order->discount_total ?? 0.00),
            'is_discount_before_tax' => true,
            'notes' => trim(($order->notes ?? '') . ($order->coupon_code ? " (Coupon: {$order->coupon_code})" : '')),
        ];

        if (!empty($order->zoho_sales_order_id)) {
            $invoicePayload['salesorder_id'] = $order->zoho_sales_order_id;
        }

        // 4. Duplicate Prevention & Zoho API Dispatch
        $localInvoice = Invoice::where('shop_id', $this->shop->id)
            ->where('order_id', $order->id)
            ->first();

        $zohoInvoiceId = $localInvoice->zoho_invoice_id ?? null;
        $created = false;
        $updated = false;

        if (empty($zohoInvoiceId) && !empty($order->order_number)) {
            $existingZohoInv = $this->findZohoInvoiceByReferenceNumber($order->order_number);
            if ($existingZohoInv && !empty($existingZohoInv['invoice_id'])) {
                $zohoInvoiceId = $existingZohoInv['invoice_id'];
            }
        }

        try {
            if ($zohoInvoiceId) {
                if (!$localInvoice) {
                    $localInvoice = Invoice::create([
                        'shop_id' => $this->shop->id,
                        'order_id' => $order->id,
                        'shopify_order_id' => $order->shopify_order_id,
                        'zoho_invoice_id' => $zohoInvoiceId,
                        'invoice_number' => $existingZohoInv['invoice_number'] ?? null,
                        'status' => $existingZohoInv['status'] ?? 'created',
                        'invoice_date' => $order->order_date,
                        'amount' => $order->total_price,
                        'currency' => $order->currency ?? 'USD',
                        'sync_status' => 'synced',
                        'synced_at' => now(),
                    ]);
                }

                $zohoData = $this->updateInvoice($localInvoice, $order, $invoicePayload);
                $updated = true;
            } else {
                $zohoData = $this->createInvoice($order, $invoicePayload);
                $zohoInvoiceId = $zohoData['invoice_id'] ?? null;
                $created = true;
            }
        } catch (\Throwable $e) {
            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
                'invoice_id' => $localInvoice->id ?? null,
                'action' => 'create',
                'status' => 'failed',
                'zoho_invoice_id' => $zohoInvoiceId,
                'message' => 'Zoho Invoice sync failed: ' . $e->getMessage(),
                'synced_at' => now(),
            ]);

            throw $e;
        }

        if (empty($zohoInvoiceId)) {
            throw new \Exception("Failed to obtain Zoho Invoice ID for order #{$order->order_number}");
        }

        // 5. Update local Invoice persistence
        $invoice = Invoice::updateOrCreate(
            [
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
            ],
            [
                'shopify_order_id' => $order->shopify_order_id,
                'zoho_invoice_id' => $zohoInvoiceId,
                'invoice_number' => $zohoData['invoice_number'] ?? ($localInvoice->invoice_number ?? null),
                'status' => $zohoData['status'] ?? 'created',
                'invoice_date' => $order->order_date,
                'amount' => $order->total_price,
                'currency' => $order->currency ?? 'USD',
                'sync_status' => 'synced',
                'synced_at' => now(),
            ]
        );

        // 6. Record Sync History
        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'action' => $created ? 'create' : 'update',
            'status' => 'success',
            'zoho_invoice_id' => $zohoInvoiceId,
            'message' => $created ? 'Invoice created in Zoho Books.' : 'Invoice updated in Zoho Books.',
            'synced_at' => now(),
        ]);

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'zoho_invoice_id' => $zohoInvoiceId,
            'invoice_number' => $invoice->invoice_number,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'message' => $created ? 'Invoice created successfully.' : 'Invoice updated successfully.',
        ];
    }

    private function getSyncHash(ProductVariant $variant): string {
        return hash('sha256', json_encode([
            'title' => $variant->title,
            'sku' => $variant->sku,
            'price' => $variant->price,
        ]));
    }
}



