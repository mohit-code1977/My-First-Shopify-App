<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ZohoConnection;
use Illuminate\Support\Facades\Log;

/**
 * Organization-specific Integration Preflight Service for Zoho Books.
 *
 * Ensures that newly connected Zoho organizations have all required custom fields,
 * price lists, currency configurations, and capabilities provisioned automatically.
 */
class ZohoPreflightService
{
    /**
     * Required custom fields per entity to ensure zero-failure synchronization.
     */
    private const REQUIRED_CUSTOM_FIELDS = [
        [
            'entity' => 'item',
            'api_name' => 'cf_shopify_variant_id',
            'label' => 'Shopify Variant ID',
            'data_type' => 'string',
            'required' => true,
        ],
        [
            'entity' => 'item',
            'api_name' => 'cf_shopify_product_id',
            'label' => 'Shopify Product ID',
            'data_type' => 'string',
            'required' => true,
        ],
        [
            'entity' => 'salesorder',
            'api_name' => 'cf_shopify_order_id',
            'label' => 'Shopify Order ID',
            'data_type' => 'string',
            'required' => true,
        ],
        [
            'entity' => 'invoice',
            'api_name' => 'cf_shopify_order_id',
            'label' => 'Shopify Order ID',
            'data_type' => 'string',
            'required' => false,
        ],
    ];

    /**
     * Run the complete integration preflight for a given shop.
     *
     * @param Shop $shop
     * @return array Preflight execution results and readiness state.
     */
    public function run(Shop $shop): array
    {
        $connection = ZohoConnection::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->first();

        if (!$connection) {
            return [
                'success' => false,
                'status' => 'disconnected',
                'readiness_label' => 'Disconnected',
                'message' => 'No active Zoho Books connection found.',
            ];
        }

        $zohoService = new ZohoService($shop);
        $priceListService = new ZohoPriceListService($zohoService, $shop);

        $createdConfigs = [];
        $reusedConfigs = [];
        $missingErrors = [];
        $customFieldMappings = $connection->custom_field_mappings ?? [];

        Log::info("ZohoPreflightService: Starting preflight check for shop {$shop->id} (Org ID: {$connection->organization_id})");

        // 1. Check and Provision Custom Fields
        $fieldsResult = $this->checkAndProvisionCustomFields($zohoService, $customFieldMappings);
        $customFieldMappings = $fieldsResult['mappings'];
        $createdConfigs = array_merge($createdConfigs, $fieldsResult['created']);
        $reusedConfigs = array_merge($reusedConfigs, $fieldsResult['reused']);
        $missingErrors = array_merge($missingErrors, $fieldsResult['errors']);

        // 2. Check and Provision Price List
        $shopCurrency = strtoupper(trim($shop->currency ?? 'USD'));
        $priceListResult = $this->checkPriceList($priceListService, $shopCurrency);
        if ($priceListResult['status'] === 'created') {
            $createdConfigs[] = $priceListResult['message'];
        } elseif ($priceListResult['status'] === 'reused') {
            $reusedConfigs[] = $priceListResult['message'];
        } elseif ($priceListResult['status'] === 'error') {
            $missingErrors[] = $priceListResult['message'];
        }

        // 3. Check Multi-Currency Capability
        $currencyResult = $this->checkMultiCurrency($zohoService, $shopCurrency);
        if ($currencyResult['status'] === 'error') {
            $missingErrors[] = $currencyResult['message'];
        } else {
            $reusedConfigs[] = $currencyResult['message'];
        }

        // 4. Check Inventory Capability
        $inventoryCapability = $zohoService->detectInventoryCapability();
        $connection->inventory_capability = $inventoryCapability;

        // 5. Check Taxes Configuration
        $taxResult = $this->checkTaxes($zohoService);

        // 6. Calculate Readiness Status
        $setupStatus = empty($missingErrors) ? 'ready' : 'setup_required';
        $readinessLabel = match ($setupStatus) {
            'ready' => 'Integration Ready',
            'setup_required' => 'Connected — Setup Required',
            default => 'Connected',
        };

        $summary = [
            'readiness_label' => $readinessLabel,
            'setup_status' => $setupStatus,
            'organization_id' => $connection->organization_id,
            'organization_name' => $connection->organization_name,
            'shop_currency' => $shopCurrency,
            'inventory_capability' => $inventoryCapability,
            'price_list' => $priceListResult['details'] ?? null,
            'currencies' => $currencyResult['details'] ?? null,
            'taxes' => $taxResult['details'] ?? null,
            'created_configurations' => $createdConfigs,
            'reused_configurations' => $reusedConfigs,
            'missing_configurations' => $missingErrors,
            'executed_at' => now()->toIso8601String(),
        ];

        // Save preflight results to ZohoConnection
        $connection->update([
            'setup_status' => $setupStatus,
            'custom_field_mappings' => $customFieldMappings,
            'setup_summary' => $summary,
            'preflight_run_at' => now(),
        ]);

        Log::info("ZohoPreflightService: Completed preflight for shop {$shop->id}. Status: {$setupStatus}. Readiness: {$readinessLabel}. Created: " . count($createdConfigs) . ", Reused: " . count($reusedConfigs) . ", Errors: " . count($missingErrors));

        return [
            'success' => true,
            'status' => $setupStatus,
            'readiness_label' => $readinessLabel,
            'summary' => $summary,
            'custom_field_mappings' => $customFieldMappings,
        ];
    }

    /**
     * Check existing custom fields and provision any missing required fields.
     */
    private function checkAndProvisionCustomFields(ZohoService $zohoService, array $existingMappings): array
    {
        $created = [];
        $reused = [];
        $errors = [];
        $mappings = $existingMappings;

        // Group required fields by entity to minimize API calls
        $entities = ['item', 'salesorder', 'invoice'];

        foreach ($entities as $entity) {
            $existingFieldsInZoho = [];
            try {
                $response = $zohoService->makeRequest('GET', '/books/v3/settings/fields', [
                    'entity' => $entity,
                    'filter_custom_fields' => 'true',
                    'skip_inactive_fields' => 'true',
                ]);
                $existingFieldsInZoho = $response['fields'] ?? [];
            } catch (\Throwable $e) {
                Log::warning("ZohoPreflightService: Failed to fetch fields for entity {$entity}: " . $e->getMessage());
            }

            // Find required fields for this entity
            $entityReqs = array_filter(self::REQUIRED_CUSTOM_FIELDS, fn($f) => $f['entity'] === $entity);

            foreach ($entityReqs as $fieldSpec) {
                $expectedApiName = $fieldSpec['api_name'];
                $expectedLabel = $fieldSpec['label'];
                $matchedField = null;

                // Match by api_name or label (case-insensitive)
                foreach ($existingFieldsInZoho as $f) {
                    $apiName = $f['api_name'] ?? null;
                    $labelName = $f['label_name'] ?? null;

                    if ($apiName === $expectedApiName || (is_string($labelName) && strcasecmp($labelName, $expectedLabel) === 0)) {
                        $matchedField = $f;
                        break;
                    }
                }

                if ($matchedField) {
                    $fieldId = (string) ($matchedField['field_id'] ?? $matchedField['customfield_id'] ?? '');
                    $mappings[$entity][$expectedApiName] = [
                        'field_id' => $fieldId,
                        'customfield_id' => (string) ($matchedField['customfield_id'] ?? $fieldId),
                        'api_name' => $matchedField['api_name'] ?? $expectedApiName,
                        'label_name' => $matchedField['label_name'] ?? $expectedLabel,
                        'data_type' => $matchedField['data_type'] ?? $fieldSpec['data_type'],
                        'is_active' => true,
                    ];
                    $reused[] = "Reused existing custom field '{$expectedLabel}' (ID: {$fieldId}) for {$entity}.";
                } else {
                    // Custom field missing — create it via Zoho API
                    try {
                        $createRes = $zohoService->makeRequest('POST', '/books/v3/settings/fields', [
                            'entity' => $entity,
                            'label' => $expectedLabel,
                            'label_name' => $expectedLabel,
                            'data_type' => $fieldSpec['data_type'],
                            'show_on_pdf' => false,
                            'show_in_store' => false,
                        ]);

                        $newField = $createRes['data'] ?? $createRes['customfield'] ?? $createRes['field'] ?? [];
                        $fieldId = (string) ($newField['field_id'] ?? $newField['customfield_id'] ?? $createRes['field_id'] ?? $createRes['customfield_id'] ?? '');

                        if ($fieldId) {
                            $mappings[$entity][$expectedApiName] = [
                                'field_id' => $fieldId,
                                'customfield_id' => (string) ($newField['customfield_id'] ?? $fieldId),
                                'api_name' => $newField['api_name'] ?? $newField['field_name'] ?? $expectedApiName,
                                'label_name' => $expectedLabel,
                                'data_type' => $fieldSpec['data_type'],
                                'is_active' => true,
                            ];
                            $created[] = "Created missing custom field '{$expectedLabel}' (ID: {$fieldId}) for {$entity}.";
                        } else {
                            if ($fieldSpec['required']) {
                                $errors[] = "Failed to create custom field '{$expectedLabel}' for {$entity}: Zoho did not return field_id.";
                            }
                        }
                    } catch (\Throwable $createEx) {
                        Log::error("ZohoPreflightService: Failed to create custom field '{$expectedLabel}' for {$entity}: " . $createEx->getMessage());
                        if ($fieldSpec['required']) {
                            $errors[] = "Required custom field '{$expectedLabel}' for {$entity} is missing and creation failed: " . $createEx->getMessage();
                        }
                    }
                }
            }
        }

        return [
            'mappings' => $mappings,
            'created' => $created,
            'reused' => $reused,
            'errors' => $errors,
        ];
    }

    /**
     * Check or create the integration-managed price list for the store's currency.
     */
    private function checkPriceList(ZohoPriceListService $priceListService, string $currencyCode): array
    {
        try {
            $existing = $priceListService->findManagedPriceList($currencyCode);
            if ($existing && !empty($existing['pricebook_id'])) {
                return [
                    'status' => 'reused',
                    'message' => "Reused existing Price List '{$existing['name']}' (ID: {$existing['pricebook_id']}).",
                    'details' => [
                        'pricebook_id' => (string) $existing['pricebook_id'],
                        'name' => $existing['name'],
                        'currency' => $currencyCode,
                    ],
                ];
            }

            $created = $priceListService->getOrCreatePriceList($currencyCode);
            if (!empty($created['pricebook_id'])) {
                return [
                    'status' => 'created',
                    'message' => "Created new Price List '{$created['name']}' (ID: {$created['pricebook_id']}) for currency {$currencyCode}.",
                    'details' => [
                        'pricebook_id' => (string) $created['pricebook_id'],
                        'name' => $created['name'],
                        'currency' => $currencyCode,
                    ],
                ];
            }

            return [
                'status' => 'error',
                'message' => "Failed to provision Price List for currency {$currencyCode}.",
                'details' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => "Price List check failed for currency {$currencyCode}: " . $e->getMessage(),
                'details' => null,
            ];
        }
    }

    /**
     * Check if store currency is supported/enabled in Zoho Books.
     */
    private function checkMultiCurrency(ZohoService $zohoService, string $currencyCode): array
    {
        try {
            $currencies = $zohoService->getCurrencies();
            $currencyCode = strtoupper($currencyCode);

            foreach ($currencies as $curr) {
                if (strtoupper(trim($curr['currency_code'] ?? '')) === $currencyCode) {
                    return [
                        'status' => 'ok',
                        'message' => "Store currency '{$currencyCode}' is enabled in Zoho Books (Currency ID: {$curr['currency_id']}).",
                        'details' => [
                            'store_currency' => $currencyCode,
                            'currency_id' => (string) ($curr['currency_id'] ?? ''),
                            'is_enabled' => true,
                            'total_currencies_configured' => count($currencies),
                        ],
                    ];
                }
            }

            return [
                'status' => 'error',
                'message' => "Store currency '{$currencyCode}' is not enabled in Zoho Books under Settings → Currencies.",
                'details' => [
                    'store_currency' => $currencyCode,
                    'is_enabled' => false,
                    'total_currencies_configured' => count($currencies),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'ok', // Non-fatal if fetch fails, but log warning
                'message' => "Currency verification warning: " . $e->getMessage(),
                'details' => null,
            ];
        }
    }

    /**
     * Check active taxes in Zoho Books.
     */
    private function checkTaxes(ZohoService $zohoService): array
    {
        try {
            $response = $zohoService->makeRequest('GET', '/books/v3/settings/taxes');
            $taxes = $response['taxes'] ?? [];

            return [
                'status' => 'ok',
                'details' => [
                    'active_taxes_count' => count($taxes),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'ok',
                'details' => [
                    'active_taxes_count' => 0,
                    'warning' => $e->getMessage(),
                ],
            ];
        }
    }
}
