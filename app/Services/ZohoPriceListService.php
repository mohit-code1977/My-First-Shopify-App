<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Currency-aware Price List management for Zoho Books.
 *
 * Implements the proven architecture:
 * - Zoho Item Master uses base currency (organization default, e.g., INR)
 * - A currency-specific Price List stores the Shopify price in the store's currency
 * - Sales Orders/Invoices reference the Price List + currency_id for correct display
 *
 * This service maintains exactly one integration-managed Zoho Price List per currency.
 */
class ZohoPriceListService
{
    private ZohoService $zohoService;
    private Shop $shop;

    /**
     * Price List naming convention.
     * Format: "Shopify {CURRENCY} Price List"
     */
    private const PRICE_LIST_NAME_PREFIX = 'Shopify';
    private const PRICE_LIST_NAME_SUFFIX = 'Price List';

    public function __construct(ZohoService $zohoService, Shop $shop)
    {
        $this->zohoService = $zohoService;
        $this->shop = $shop;
    }

    /**
     * Generate the deterministic price list name for a given currency.
     */
    public function getPriceListName(string $currencyCode): string
    {
        return self::PRICE_LIST_NAME_PREFIX . ' ' . strtoupper($currencyCode) . ' ' . self::PRICE_LIST_NAME_SUFFIX;
    }

    /**
     * Fetch all price lists from Zoho Books.
     */
    public function getPriceLists(): array
    {
        try {
            $response = $this->zohoService->makeRequest('GET', '/books/v3/pricebooks');
            return $response['pricebooks'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('ZohoPriceListService: Failed to fetch price lists: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Find an integration-managed price list by currency code.
     * Returns null if not found.
     */
    public function findManagedPriceList(string $currencyCode): ?array {
        $expectedName = $this->getPriceListName($currencyCode);
        $priceLists = $this->getPriceLists();

        foreach ($priceLists as $pl) {
            $plName = trim($pl['name'] ?? '');
            if (strcasecmp($plName, $expectedName) === 0) {
                return $pl;
            }
        }

        return null;
    }

    /**
     * Get or create the integration-managed price list for a given currency.
     * Idempotent: reuses existing matching price list, creates only if missing.
     *
     * @return array The price list record (with pricebook_id)
     */
    public function getOrCreatePriceList(string $currencyCode, ?string $initialItemId = null, ?float $initialRate = null): array
    {
        $currencyCode = strtoupper(trim($currencyCode));

        // 1. Try to find existing managed price list
        $existing = $this->findManagedPriceList($currencyCode);
        if ($existing && !empty($existing['pricebook_id'])) {
            Log::info("ZohoPriceListService: Reusing existing price list '{$existing['name']}' (ID: {$existing['pricebook_id']}) for currency {$currencyCode}.");
            return $existing;
        }

        // 2. Resolve the Zoho currency_id for this currency code
        $currencyId = $this->resolveCurrencyId($currencyCode);
        if (!$currencyId) {
            throw new \Exception("ZohoPriceListService: Currency '{$currencyCode}' is not enabled in Zoho Books. Please enable it under Settings → Currencies in Zoho Books.");
        }

        // 3. Create the price list
        $name = $this->getPriceListName($currencyCode);
        $payload = [
            'name' => $name,
            'currency_id' => $currencyId,
            'description' => "Managed by Shopify integration. Prices in {$currencyCode}.",
            'sales_or_purchase_type' => 'sales',
            'rounding_type' => 'no_rounding',
        ];

        if ($initialItemId && $initialRate !== null) {
            $payload['pricebook_type'] = 'per_item';
            $payload['pricebook_items'] = [
                [
                    'item_id' => $initialItemId,
                    'pricebook_rate' => (float) $initialRate,
                ],
            ];
        } else {
            try {
                $itemsResponse = $this->zohoService->makeRequest('GET', '/books/v3/items', ['per_page' => 1]);
                $firstItem = $itemsResponse['items'][0] ?? null;
                if ($firstItem && !empty($firstItem['item_id'])) {
                    $payload['pricebook_type'] = 'per_item';
                    $payload['pricebook_items'] = [
                        [
                            'item_id' => (string) $firstItem['item_id'],
                            'pricebook_rate' => (float) ($firstItem['rate'] ?? 0.00),
                        ],
                    ];
                } else {
                    $payload['pricebook_type'] = 'fixed_percentage';
                    $payload['percentage'] = 0;
                    $payload['percentage_type'] = 'markup';
                }
            } catch (\Throwable $ex) {
                Log::warning("ZohoPriceListService: Could not fetch initial item for pricebook creation: " . $ex->getMessage());
                $payload['pricebook_type'] = 'fixed_percentage';
                $payload['percentage'] = 0;
                $payload['percentage_type'] = 'markup';
            }
        }

        try {
            $response = $this->zohoService->makeRequest('POST', '/books/v3/pricebooks', $payload);
            $priceList = $response['pricebook'] ?? [];

            if (empty($priceList['pricebook_id'])) {
                throw new \Exception('Zoho did not return a pricebook_id when creating price list.');
            }

            Log::info("ZohoPriceListService: Created new price list '{$name}' (ID: {$priceList['pricebook_id']}) for currency {$currencyCode}.");
            return $priceList;
        } catch (\Throwable $e) {
            // Handle duplicate name error — another process may have created it
            if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), '1001')) {
                Log::info("ZohoPriceListService: Price list '{$name}' already exists (race condition). Fetching existing.");
                $existing = $this->findManagedPriceList($currencyCode);
                if ($existing && !empty($existing['pricebook_id'])) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * Resolve Zoho currency_id from a currency code (e.g., 'USD' → '460000000000097').
     */
    public function resolveCurrencyId(string $currencyCode): ?string
    {
        $currencyCode = strtoupper(trim($currencyCode));
        $currencies = $this->zohoService->getCurrencies();

        foreach ($currencies as $currency) {
            if (strtoupper(trim($currency['currency_code'] ?? '')) === $currencyCode) {
                return (string) ($currency['currency_id'] ?? '');
            }
        }

        return null;
    }

    /**
     * Update or add an item's price in the managed price list for the shop's currency.
     *
     * @param string $priceListId  Zoho pricebook_id
     * @param string $itemId       Zoho item_id
     * @param float  $rate         Price in the price list's currency
     */
    public function updatePriceListItem(string $priceListId, string $itemId, float $rate): array
    {
        $payload = [
            'pricebook_rate' => $rate,
        ];

        try {
            // Try updating existing item in price list
            $response = $this->zohoService->makeRequest(
                'PUT',
                "/books/v3/pricebooks/{$priceListId}/items/{$itemId}",
                $payload
            );
            return $response;
        } catch (\Throwable $e) {
            // If item not in price list, add it
            if (str_contains(strtolower($e->getMessage()), 'not found') ||
                str_contains($e->getMessage(), '1002') ||
                str_contains($e->getMessage(), '5')) {
                try {
                    $addPayload = [
                        'item_id' => $itemId,
                        'pricebook_rate' => $rate,
                    ];
                    $response = $this->zohoService->makeRequest(
                        'POST',
                        "/books/v3/pricebooks/{$priceListId}/items",
                        $addPayload
                    );
                    return $response;
                } catch (\Throwable $addEx) {
                    Log::error("ZohoPriceListService: Failed to add item {$itemId} to price list {$priceListId}: " . $addEx->getMessage());
                    throw $addEx;
                }
            }
            throw $e;
        }
    }

    /**
     * Sync a product variant's price to the appropriate currency price list.
     * Called after createItem() and updateItem().
     *
     * This method:
     * 1. Determines the shop's currency
     * 2. Gets or creates the managed price list for that currency
     * 3. Updates the item's rate in the price list
     *
     * Failures are logged but do not fail the item sync.
     */
    public function syncVariantToPriceList(ProductVariant $variant): ?array
    {
        if (!$variant->zoho_item_id) {
            return null;
        }

        $shopCurrency = strtoupper(trim($this->shop->currency ?? 'USD'));

        // If shop currency matches Zoho base currency, price list is optional
        // but we still create it for consistency and explicit currency control
        try {
            $priceList = $this->getOrCreatePriceList($shopCurrency);

            if (empty($priceList['pricebook_id'])) {
                Log::warning("ZohoPriceListService: Could not resolve price list for currency {$shopCurrency}.");
                return null;
            }

            $rate = (float) $variant->price;
            $result = $this->updatePriceListItem(
                (string) $priceList['pricebook_id'],
                $variant->zoho_item_id,
                $rate
            );

            Log::info("ZohoPriceListService: Synced variant {$variant->id} (Zoho item {$variant->zoho_item_id}) to price list '{$priceList['name']}' at rate {$rate} {$shopCurrency}.");

            return [
                'pricebook_id' => (string) $priceList['pricebook_id'],
                'pricebook_name' => $priceList['name'] ?? '',
                'currency' => $shopCurrency,
                'rate' => $rate,
            ];
        } catch (\Throwable $e) {
            Log::warning("ZohoPriceListService: Failed to sync variant {$variant->id} to price list for {$shopCurrency}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve the pricebook_id and currency_id for use in Sales Orders / Invoices.
     *
     * @param string $currencyCode  The transaction currency (e.g., 'USD')
     * @return array{pricebook_id: string|null, currency_id: string|null, currency_code: string}
     */
    public function resolveTransactionCurrency(string $currencyCode): array
    {
        $currencyCode = strtoupper(trim($currencyCode));

        $result = [
            'pricebook_id' => null,
            'currency_id' => null,
            'currency_code' => $currencyCode,
        ];

        try {
            $priceList = $this->findManagedPriceList($currencyCode);
            if ($priceList && !empty($priceList['pricebook_id'])) {
                $result['pricebook_id'] = (string) $priceList['pricebook_id'];
            }
        } catch (\Throwable $e) {
            Log::warning("ZohoPriceListService: Could not find price list for {$currencyCode}: " . $e->getMessage());
        }

        try {
            $currencyId = $this->resolveCurrencyId($currencyCode);
            if ($currencyId) {
                $result['currency_id'] = $currencyId;
            }
        } catch (\Throwable $e) {
            Log::warning("ZohoPriceListService: Could not resolve currency_id for {$currencyCode}: " . $e->getMessage());
        }

        return $result;
    }
}
