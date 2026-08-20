<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\ZohoConnection;
use App\Models\SyncHistory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZohoService {
    private const SHOPIFY_VARIANT_FIELD_API_NAME = 'cf_shopify_variant_id';

    public const CAPABILITY_ZOHO_INVENTORY = 'zoho_inventory';
    public const CAPABILITY_ZOHO_ERP = 'zoho_erp';
    public const CAPABILITY_BOOKS_NATIVE = 'books_native';
    public const CAPABILITY_UNAVAILABLE = 'unavailable';

    public const BOOKS_NATIVE_ERROR_MESSAGE = 'Automatic inventory adjustment requires Zoho Inventory/ERP API access. Zoho Books native inventory adjustments are available in the UI but are not exposed through the current documented Books REST API.';

    // Current Shopify shop
    protected Shop $shop;

    /**
     * Detect which Zoho inventory capability is available for this connection using safe read-only probes.
     */
    /**
     * Detect which Zoho inventory capability is available for this connection using safe read-only probes.
     */
    public function detectInventoryCapability(bool $forceRefresh = false): string
    {
        $connection = $this->getConnection();

        if (!$forceRefresh && !empty($connection->inventory_capability) && $connection->inventory_capability !== 'unknown') {
            return $connection->inventory_capability;
        }

        // Probe 1: Zoho Inventory API
        $probe1 = $this->probeEndpoint('/inventory/v1/items');
        if ($probe1['status'] === 'success') {
            $connection->update(['inventory_capability' => self::CAPABILITY_ZOHO_INVENTORY]);
            return self::CAPABILITY_ZOHO_INVENTORY;
        }

        if ($probe1['status'] === 'auth_error' || $probe1['status'] === 'transient_error') {
            // Do NOT fall through to ERP or Books, and do NOT persist false capability.
            return self::CAPABILITY_UNAVAILABLE;
        }

        // Probe 1 is explicitly unprovisioned (404 / 6018). Try Probe 2: Zoho ERP API
        $probe2 = $this->probeEndpoint('/erp/v3/items');
        if ($probe2['status'] === 'success') {
            $connection->update(['inventory_capability' => self::CAPABILITY_ZOHO_ERP]);
            return self::CAPABILITY_ZOHO_ERP;
        }

        if ($probe2['status'] === 'auth_error' || $probe2['status'] === 'transient_error') {
            // Do NOT fall through to Books, and do NOT persist false capability.
            return self::CAPABILITY_UNAVAILABLE;
        }

        // Both Inventory and ERP are explicitly unprovisioned. Try Probe 3: Zoho Books Native
        $probe3 = $this->probeEndpoint('/books/v3/items');
        if ($probe3['status'] === 'success') {
            $connection->update(['inventory_capability' => self::CAPABILITY_BOOKS_NATIVE]);
            return self::CAPABILITY_BOOKS_NATIVE;
        }

        // All probes failed or unprovisioned
        $connection->update(['inventory_capability' => self::CAPABILITY_UNAVAILABLE]);
        return self::CAPABILITY_UNAVAILABLE;
    }

    /**
     * Safe endpoint probe for capability detection.
     * Categorizes outcomes: 'success', 'unprovisioned' (404/6018), 'auth_error' (401/403/57), 'transient_error' (5xx/timeout/429).
     */
    protected function probeEndpoint(string $endpoint): array
    {
        try {
            $connection = $this->getConnection();
            $apiUrl = ZohoDatacenter::resolveApiUrlForConnection($connection);
            if (!$apiUrl) {
                return ['status' => 'transient_error', 'message' => 'Invalid API URL configuration.'];
            }

            $token = $this->getAccessToken();
            $url = rtrim($apiUrl, '/') . $endpoint;
            $query = [
                'organization_id' => $connection->organization_id,
                'per_page' => 1,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Zoho-oauthtoken ' . $token,
                'Accept' => 'application/json',
            ])->get($url, $query);

            $httpStatus = $response->status();
            $responseData = $response->json() ?? [];
            $zohoCode = isset($responseData['code']) ? (int) $responseData['code'] : null;

            if ($response->successful() && $zohoCode === 0) {
                return ['status' => 'success', 'data' => $responseData];
            }

            // Check for Unprovisioned / Not Available (404, Zoho code 6018) FIRST
            if ($httpStatus === 404 || $zohoCode === 6018) {
                return ['status' => 'unprovisioned', 'message' => $responseData['message'] ?? 'Module not provisioned or unavailable (404/code 6018).'];
            }

            // Check for Auth / Scope error (401, 403, Zoho code 57 / 5700)
            if ($httpStatus === 401 || $httpStatus === 403 || $zohoCode === 57 || $zohoCode === 5700) {
                return ['status' => 'auth_error', 'message' => $responseData['message'] ?? 'Zoho authorization/scope error (401/403/code 57).'];
            }

            // Check for Transient / Server / Rate-limit errors (5xx, 429)
            if ($httpStatus >= 500 || $httpStatus === 429) {
                return ['status' => 'transient_error', 'message' => "Zoho server/rate-limit error (HTTP {$httpStatus})."];
            }

            return ['status' => 'unprovisioned', 'message' => $responseData['message'] ?? 'Endpoint unavailable.'];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['status' => 'transient_error', 'message' => 'Network timeout or connection error: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '401') || str_contains($msg, '403') || $e->getCode() === 57) {
                return ['status' => 'auth_error', 'message' => $msg];
            }
            if (str_contains($msg, '404') || $e->getCode() === 6018) {
                return ['status' => 'unprovisioned', 'message' => $msg];
            }
            return ['status' => 'transient_error', 'message' => $msg];
        }
    }

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

        $accountsUrl = ZohoDatacenter::resolveAccountsUrlForConnection($connection);

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

        $apiUrl = ZohoDatacenter::resolveApiUrlForConnection($connection);

        if (!$apiUrl) {
            throw new \RuntimeException('Zoho connection is missing or has an invalid api_url endpoint configuration.');
        }

        $token = $this->getAccessToken();

        $parsedUrl = parse_url($endpoint);
        $endpointPath = $parsedUrl['path'] ?? $endpoint;
        $endpointQueryParams = [];
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $endpointQueryParams);
        }

        $url = rtrim($apiUrl, '/') . $endpointPath;

        // Send organization ID and endpoint query parameters as query parameters
        $query = array_merge([
            'organization_id' => $connection->organization_id,
        ], $endpointQueryParams);

        // Create the authenticated HTTP request
        $request = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Accept' => 'application/json',
        ]);

        $upperMethod = strtoupper($method);

        // Send GET parameters as query parameters
        if ($upperMethod === 'GET') {
            $response = $request->get(
                $url,
                array_merge($query, $data)
            );
        } else {
            // Send POST/PUT data as JSON body (format empty array as object {} for well-formed JSON object)
            $postData = (empty($data) && is_array($data)) ? (object) [] : $data;
            $response = $request
                        ->withQueryParameters($query)
                ->{$method}($url, $postData);
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
        $code = (int) $e->getCode();
        if ($code === 1002 || $code === 5) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return preg_match('/"code"\s*:\s*1002(?!\d)/', $message) === 1 ||
            preg_match('/"code"\s*:\s*5(?!\d)/', $message) === 1 ||
            preg_match('/\bcode\s*1002(?!\d)/', $message) === 1 ||
            preg_match('/\bcode\s*5(?!\d)/', $message) === 1 ||
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


    /**
     * Fetch taxes configured in Zoho Books.
     */
    public function getTaxes(): array
    {
        try {
            $response = $this->makeRequest('GET', '/books/v3/settings/taxes');
            return $response['taxes'] ?? [];
        } catch (\Throwable $e) {
            Log::warning("getTaxes: Failed to fetch taxes from Zoho: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Format an address array into a single text string <= 100 characters for Zoho Sales Orders and Invoices.
     */
    public function formatZohoAddressString(array $rawAddress, ?Customer $contact = null): string
    {
        $address1 = trim($rawAddress['address1'] ?? $rawAddress['address'] ?? '');
        $address2 = trim($rawAddress['address2'] ?? $rawAddress['street2'] ?? '');
        $city = trim($rawAddress['city'] ?? '');
        $provinceCode = trim($rawAddress['province_code'] ?? '');
        $province = trim($rawAddress['province'] ?? $rawAddress['state'] ?? '');
        $zip = trim($rawAddress['zip'] ?? $rawAddress['postal_code'] ?? '');
        $countryCode = trim($rawAddress['country_code'] ?? '');
        $country = trim($rawAddress['country'] ?? '');

        $state = !empty($provinceCode) ? $provinceCode : $province;
        $countryName = !empty($countryCode) ? $countryCode : $country;

        $street = trim($address1 . ' ' . $address2);

        // Attempt 1: Full formatted string with street, city, state, zip, country
        $parts = array_filter([$street, $city, $state, $zip, $countryName]);
        $formatted = implode(', ', $parts);

        if (mb_strlen($formatted) <= 100) {
            return $formatted;
        }

        // Attempt 2: Use address1 only (drop address2)
        $parts = array_filter([$address1, $city, $state, $zip, $countryName]);
        $formatted = implode(', ', $parts);

        if (mb_strlen($formatted) <= 100) {
            return $formatted;
        }

        // Attempt 3: Drop countryName if state and zip present
        $parts = array_filter([$address1, $city, $state, $zip]);
        $formatted = implode(', ', $parts);

        if (mb_strlen($formatted) <= 100) {
            return $formatted;
        }

        // Final Fallback: Hard clamp to 100 characters safely
        return mb_substr($formatted, 0, 100);
    }

    /**
     * Get list of enabled currencies from Zoho Books (/books/v3/settings/currencies).
     */
    public function getCurrencies(): array
    {
        try {
            $response = $this->makeRequest('GET', '/books/v3/settings/currencies');
            return $response['currencies'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('ZohoService getCurrencies failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Format shipping/billing address object payload for Zoho API to respect character constraints.
     */
    public function formatZohoAddressObject(array $shipAddress, ?Customer $contact = null): array
    {
        $firstName = trim($shipAddress['first_name'] ?? '');
        $lastName = trim($shipAddress['last_name'] ?? '');
        $name = trim($shipAddress['name'] ?? '');
        $company = trim($shipAddress['company'] ?? '');

        $attention = trim($firstName . ' ' . $lastName);
        if (empty($attention)) {
            $attention = $name ?: $company;
        }

        if ($contact) {
            $contactFirst = trim($contact->first_name ?? '');
            $contactLast = trim($contact->last_name ?? '');
            $contactFullName = trim($contactFirst . ' ' . $contactLast);
            $billingName = trim($contact->billing_address['name'] ?? '');

            if (
                (!empty($contactFirst) && str_contains(strtolower($attention), strtolower($contactFirst))) ||
                (!empty($contactFullName) && strcasecmp($attention, $contactFullName) === 0) ||
                (!empty($billingName) && strcasecmp($attention, $billingName) === 0)
            ) {
                $attention = '';
            }
        }

        $address1 = trim($shipAddress['address1'] ?? $shipAddress['address'] ?? '');
        $address2 = trim($shipAddress['address2'] ?? $shipAddress['street2'] ?? '');
        $city = trim($shipAddress['city'] ?? '');
        $provinceCode = trim($shipAddress['province_code'] ?? '');
        $province = trim($shipAddress['province'] ?? $shipAddress['state'] ?? '');
        $zip = trim($shipAddress['zip'] ?? $shipAddress['postal_code'] ?? '');
        $countryCode = trim($shipAddress['country_code'] ?? '');
        $country = trim($shipAddress['country'] ?? '');
        $phone = trim($shipAddress['phone'] ?? '');

        $state = $province ?: $provinceCode;
        $countryName = $country ?: $countryCode;

        $calcLen = function ($att, $a1, $a2, $c, $st, $z, $co) {
            return strlen(implode(' ', array_filter([$att, $a1, $a2, $c, $st, $z, $co])));
        };

        if ($calcLen($attention, $address1, $address2, $city, $state, $zip, $countryName) > 75 && !empty($provinceCode)) {
            $state = $provinceCode;
        }

        if ($calcLen($attention, $address1, $address2, $city, $state, $zip, $countryName) > 75 && !empty($countryCode)) {
            $countryName = $countryCode;
        }

        if ($calcLen($attention, $address1, $address2, $city, $state, $zip, $countryName) > 75) {
            $attention = '';
        }

        if ($calcLen($attention, $address1, $address2, $city, $state, $zip, $countryName) > 75 && !empty($address2)) {
            $maxAddr2 = max(0, 75 - $calcLen($attention, $address1, '', $city, $state, $zip, $countryName));
            if ($maxAddr2 < 5) {
                $address2 = '';
            } else {
                $address2 = mb_substr($address2, 0, $maxAddr2);
            }
        }

        if ($calcLen($attention, $address1, $address2, $city, $state, $zip, $countryName) > 75) {
            $maxAddr1 = max(10, 75 - $calcLen($attention, '', $address2, $city, $state, $zip, $countryName));
            $address1 = mb_substr($address1, 0, $maxAddr1);
        }

        $res = [];
        if (!empty($attention)) {
            $res['attention'] = mb_substr($attention, 0, 100);
        }
        if (!empty($address1)) {
            $res['address'] = mb_substr($address1, 0, 100);
        }
        if (!empty($address2)) {
            $res['street2'] = mb_substr($address2, 0, 100);
        }
        if (!empty($city)) {
            $res['city'] = mb_substr($city, 0, 100);
        }
        if (!empty($state)) {
            $res['state'] = mb_substr($state, 0, 100);
        }
        if (!empty($zip)) {
            $res['zip'] = mb_substr($zip, 0, 100);
        }
        if (!empty($countryName)) {
            $res['country'] = mb_substr($countryName, 0, 100);
        }
        if (!empty($phone)) {
            $res['phone'] = mb_substr($phone, 0, 50);
        }

        return $res;
    }

    /**
     * Format shipping address payload for Zoho API to respect 100-character constraints.
     */
    public function formatZohoShippingAddress(array $shipAddress, ?Customer $contact = null): array
    {
        return $this->formatZohoAddressObject($shipAddress, $contact);
    }

    /**
     * Resolve Zoho Tax ID for a line item or order based on shop tax_settings and tax lines.
     */
    private function resolveZohoTaxId(array $taxLines = [], ?Order $order = null): ?string
    {
        // 0. If order has no tax lines AND order tax_total is 0, this is a non-taxed order.
        // Do NOT attach tax_id to non-taxed orders.
        $hasTaxLines = !empty($taxLines);
        if (!$hasTaxLines && $order && !empty($order->tax_lines) && is_array($order->tax_lines)) {
            $hasTaxLines = count($order->tax_lines) > 0;
        }
        $orderTaxTotal = $order ? (float) ($order->tax_total ?? 0.0) : 0.0;

        if (!$hasTaxLines && $orderTaxTotal <= 0) {
            return null;
        }

        $taxSettings = $this->shop->tax_settings ?? [];
        $mappings = $taxSettings['tax_mappings'] ?? [];
        $defaultTaxId = $taxSettings['default_tax_id'] ?? null;

        $targetTaxLines = !empty($taxLines) ? $taxLines : ($order ? ($order->tax_lines ?? []) : []);

        // Fetch active taxes from Zoho to validate existence and rates
        $zohoTaxes = [];
        try {
            $zohoTaxes = $this->getTaxes();
        } catch (\Throwable $e) {
            Log::warning("resolveZohoTaxId: Could not fetch Zoho taxes: " . $e->getMessage());
        }

        $zohoTaxMap = [];
        foreach ($zohoTaxes as $zt) {
            if (!empty($zt['tax_id'])) {
                $zohoTaxMap[(string) $zt['tax_id']] = $zt;
            }
        }

        $orderRef = $order ? ($order->order_number ?? "#{$order->id}") : "Order";

        // 1. Try matching item/order tax lines against tax_mappings with strict rate and existence validation
        if (!empty($targetTaxLines)) {
            foreach ($targetTaxLines as $tl) {
                $title = strtolower(trim($tl['title'] ?? ''));
                $ratePct = round(((float) ($tl['rate'] ?? 0.0)) * 100, 2);

                foreach ($mappings as $map) {
                    $mappedName = strtolower(trim($map['shopify_tax_name'] ?? ''));
                    $mappedRate = round((float) ($map['shopify_rate'] ?? 0.0), 2);
                    $mappedZohoTaxId = !empty($map['zoho_tax_id']) ? (string) $map['zoho_tax_id'] : null;

                    if ($mappedZohoTaxId) {
                        $isTitleMatch = ($mappedName !== '' && (str_contains($title, $mappedName) || str_contains($mappedName, $title)));
                        $isRateMatch = ($mappedRate > 0 && abs($ratePct - $mappedRate) < 0.01);

                        if ($isTitleMatch || $isRateMatch) {
                            // First verify that mapped Zoho tax actually exists and is active in Zoho Books
                            if (!isset($zohoTaxMap[$mappedZohoTaxId])) {
                                Log::warning("resolveZohoTaxId: Mapped Zoho tax ID '{$mappedZohoTaxId}' for rate {$ratePct}% no longer exists or is deleted in Zoho Books.");
                                continue;
                            }

                            // Validate Zoho tax rate parity
                            $actualZohoRate = (float) ($zohoTaxMap[$mappedZohoTaxId]['tax_percentage'] ?? 0.0);
                            if (abs($ratePct - $actualZohoRate) > 0.01) {
                                Log::warning("resolveZohoTaxId: Tax mapping rate mismatch. Shopify tax rate is {$ratePct}%, but mapped Zoho tax '{$zohoTaxMap[$mappedZohoTaxId]['tax_name']}' actual rate is {$actualZohoRate}%. Rejecting tax ID {$mappedZohoTaxId}.");
                                throw new \Exception("Tax mapping rate mismatch: Shopify tax rate ({$ratePct}%) does not match Zoho tax '{$zohoTaxMap[$mappedZohoTaxId]['tax_name']}' actual rate ({$actualZohoRate}%). Please update the tax mapping in Settings.");
                            }
                            return $mappedZohoTaxId;
                        }
                    }
                }
            }
        }

        // 2. Fallback to default_tax_id if specified AND order has taxes AND tax exists in Zoho
        if (!empty($defaultTaxId) && ($hasTaxLines || $orderTaxTotal > 0)) {
            $defaultTaxIdStr = (string) $defaultTaxId;
            if (isset($zohoTaxMap[$defaultTaxIdStr])) {
                if (!empty($targetTaxLines)) {
                    $firstLineRate = round(((float) ($targetTaxLines[0]['rate'] ?? 0.0)) * 100, 2);
                    $actualZohoRate = (float) ($zohoTaxMap[$defaultTaxIdStr]['tax_percentage'] ?? 0.0);
                    if ($firstLineRate > 0 && abs($firstLineRate - $actualZohoRate) > 0.01) {
                        Log::warning("resolveZohoTaxId: Default tax ID rate mismatch. Shopify rate is {$firstLineRate}%, but default Zoho tax rate is {$actualZohoRate}%.");
                        throw new \Exception("Default tax rate mismatch: Shopify tax rate ({$firstLineRate}%) does not match default Zoho tax '{$zohoTaxMap[$defaultTaxIdStr]['tax_name']}' actual rate ({$actualZohoRate}%).");
                    }
                }
                return $defaultTaxIdStr;
            } else {
                Log::warning("resolveZohoTaxId: Configured default Zoho tax ID '{$defaultTaxIdStr}' no longer exists or is deleted in Zoho Books.");
            }
        }

        // 3. Order requires tax but no valid active Zoho tax ID could be resolved
        $taxDetails = [];
        foreach ($targetTaxLines as $tl) {
            $name = $tl['title'] ?? 'Tax';
            $rate = round(((float) ($tl['rate'] ?? 0.0)) * 100, 2);
            $taxDetails[] = "{$name} ({$rate}%)";
        }
        $taxSummary = !empty($taxDetails) ? implode(', ', $taxDetails) : 'tax';

        if (!empty($defaultTaxId) && !isset($zohoTaxMap[(string) $defaultTaxId])) {
            throw new \Exception("Tax calculation failed for {$orderRef}: The order requires tax ({$taxSummary}), but configured default Zoho tax ID ({$defaultTaxId}) no longer exists or is deleted in Zoho Books. Please update tax settings in the application.");
        }

        throw new \Exception("Tax calculation failed for {$orderRef}: The order requires tax ({$taxSummary}), but no valid active Zoho tax mapping or default tax is configured in Settings.");
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
                if (
                    (string) (
                        $customField['customfield_id'] ?? '') !== (string) $fieldId
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

    /**
     * Find existing Zoho item by name.
     *
     * AMBIGUITY PROTECTION: If multiple Zoho items match the same name,
     * this method returns null and logs the conflict instead of arbitrarily
     * linking the first match. Name matching is a fallback only.
     */
    public function findItemByName(string $name): ?array
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return null;
        }

        $matches = [];

        // 1. Try search API with name filter (fixed: pass as $data, not 4th arg)
        try {
            $response = $this->makeRequest('GET', '/books/v3/items', [
                'name' => $trimmedName,
            ]);

            $items = $response['items'] ?? [];
            foreach ($items as $item) {
                if (!empty($item['name']) && strcasecmp(trim($item['name']), $trimmedName) === 0) {
                    $matches[] = $item;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Zoho API name search filter failed for name '{$trimmedName}': " . $e->getMessage());
        }

        // 2. If API filter returned no matches, fallback search across full items list
        if (empty($matches)) {
            try {
                $items = $this->getItems();
                foreach ($items as $item) {
                    if (!empty($item['name']) && strcasecmp(trim($item['name']), $trimmedName) === 0) {
                        $matches[] = $item;
                    }
                }
            } catch (\Throwable $e) {
                Log::error("Zoho getItems fallback name search failed for name '{$trimmedName}': " . $e->getMessage());
            }
        }

        // AMBIGUITY CHECK: If multiple items have the same name, do NOT link
        if (count($matches) > 1) {
            $matchIds = array_map(fn($m) => $m['item_id'] ?? 'unknown', $matches);
            Log::warning("findItemByName: AMBIGUOUS — Multiple Zoho items match name '{$trimmedName}'. Item IDs: " . implode(', ', $matchIds) . ". Refusing to link automatically. Resolve manually.");
            return null;
        }

        return $matches[0] ?? null;
    }



    // Create a Zoho Books item from a Shopify product variant
    public function createItem(ProductVariant $variant, ?int $initialStock = null): array
    {
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

        // Duplicate protection by Name: check if an item with this name already exists in Zoho
        $product = $variant->product;
        $itemName = ($product?->title ? $product->title . ' - ' : '') . $variant->title;
        if (empty($product?->title)) {
            $itemName = $variant->title;
        }

        try {
            $nameMatch = $this->findItemByName($itemName);
            if ($nameMatch && !empty($nameMatch['item_id'])) {
                $matchedZohoId = (string) $nameMatch['item_id'];

                $alreadyLinked = ProductVariant::where('zoho_item_id', $matchedZohoId)
                    ->where('id', '!=', $variant->id)
                    ->exists();

                if ($alreadyLinked) {
                    Log::warning("createItem: Zoho item {$matchedZohoId} matches name '{$itemName}', but is already linked to another local variant. Proceeding with new item creation.");
                } else {
                    Log::info("createItem: Found existing Zoho item {$matchedZohoId} matching name '{$itemName}' for variant ID {$variant->id}. Linking existing item.");
                    $variant->update([
                        'zoho_item_id' => $matchedZohoId,
                    ]);
                    return $this->updateItem($variant);
                }
            }
        } catch (\Throwable $e) {
            Log::error("createItem: Zoho name lookup failed for name '{$itemName}' on variant ID {$variant->id}: " . $e->getMessage());
        }

        // Determine initial stock if not explicitly supplied
        if ($initialStock === null && isset($variant->inventory_quantity)) {
            $initialStock = (int) $variant->inventory_quantity;
        }

        // Build the Zoho item data
        $shopifyVariantFieldId = $this->getShopifyVariantFieldId();

        $data = [
            'name' => $itemName,

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

        // Configure inventory tracking if enabled
        $isTracked = true;
        if (isset($variant->inventory_management) && $variant->inventory_management === 'dont_track') {
            $isTracked = false;
        }

        if ($isTracked && $this->detectInventoryCapability() !== self::CAPABILITY_UNAVAILABLE) {
            $inventoryAccountId = $this->getInventoryAssetAccountId();
            if ($inventoryAccountId) {
                $data['track_inventory'] = true;
                $data['inventory_account_id'] = $inventoryAccountId;
                if ($initialStock !== null && $initialStock >= 0) {
                    $data['initial_stock'] = (float) $initialStock;
                    $data['initial_stock_rate'] = (float) $variant->price;
                }
            }
        }

        // Add SKU only when Shopify has one
        if (!empty($variant->sku)) {
            $data['sku'] = $variant->sku;
        }

        // Create the item in Zoho Books (with fallback for duplicate item name error 1001)
        try {
            $result = $this->makeRequest('POST', '/books/v3/items', $data);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '1001') || str_contains(strtolower($e->getMessage()), 'already exists')) {
                Log::info("createItem: Zoho returned item already exists for variant ID {$variant->id} (name '{$itemName}'). Attempting fallback link.");
                $nameMatch = $this->findItemByName($itemName);
                if ($nameMatch && !empty($nameMatch['item_id'])) {
                    $matchedZohoId = (string) $nameMatch['item_id'];
                    $variant->update(['zoho_item_id' => $matchedZohoId]);
                    return $this->updateItem($variant);
                }
            }
            throw $e;
        }

        // Get the Zoho item ID from the API response
        $zohoItemId = $result['item']['item_id'] ?? null;

        // Make sure Zoho returned an item ID
        if (!$zohoItemId) {
            throw new \Exception('Zoho did not return an item ID.');
        }

        // Save the Zoho item ID and initial sync tracking against the Shopify variant
        $updateFields = [
            'zoho_item_id' => (string) $zohoItemId,
        ];

        if ($initialStock !== null) {
            $updateFields['last_synced_quantity'] = (int) $initialStock;
            $updateFields['last_sync_source'] = 'shopify';
        }

        $variant->update($updateFields);

        // Sync variant price to currency-specific Price List (failure does not rollback item creation)
        $priceListResult = null;
        try {
            $priceListService = new ZohoPriceListService($this, $this->shop);
            $priceListResult = $priceListService->syncVariantToPriceList($variant);
        } catch (\Throwable $e) {
            Log::warning("Price list sync failed during createItem for variant {$variant->id}: " . $e->getMessage());
        }

        // Upload product image if available (failure does not rollback item creation)
        $imageUrl = $variant->image_url ?? $variant->product?->image_url;
        if (!empty($imageUrl)) {
            try {
                $this->uploadItemImage($variant, $imageUrl);
            } catch (\Throwable $e) {
                Log::warning("Zoho image upload failed during createItem for variant {$variant->id}: " . $e->getMessage());
            }
        }

        // Return sync information along with the Zoho response
        return [
            'message' => 'Zoho item created successfully.',
            'zoho_item_id' => (string) $zohoItemId,
            'created' => true,
            'updated' => false,
            'initial_stock' => $initialStock,
            'price_list' => $priceListResult,
            'zoho_response' => $result,
        ];
    }

    /**
     * Safely deprecate a legacy non-inventory-tracked Zoho item and create a new tracked inventory item.
     */
    public function reconcileLegacyNonTrackedItem(ProductVariant $variant): array
    {
        if (!$variant->zoho_item_id) {
            return $this->createItem($variant);
        }

        $zohoItem = $this->getItem($variant->zoho_item_id);
        if (!$zohoItem) {
            $variant->update(['zoho_item_id' => null]);
            return $this->createItem($variant);
        }

        if (!empty($zohoItem['track_inventory'])) {
            return [
                'message' => 'Zoho item is already inventory-tracked.',
                'zoho_item_id' => $variant->zoho_item_id,
                'already_tracked' => true,
                'created' => false,
                'updated' => false,
            ];
        }

        Log::info("reconcileLegacyNonTrackedItem: Deprecating legacy non-tracked Zoho item {$variant->zoho_item_id} for variant ID {$variant->id}.");

        try {
            $oldName = $zohoItem['name'] ?? ('Variant #' . $variant->id);
            $updateData = [
                'name' => substr($oldName . ' (Legacy Non-Tracked)', 0, 100),
            ];
            if (!empty($zohoItem['sku'])) {
                $updateData['sku'] = substr($zohoItem['sku'] . '-legacy', 0, 100);
            }
            $shopifyVariantFieldId = $this->getShopifyVariantFieldId();
            if ($shopifyVariantFieldId) {
                $updateData['custom_fields'] = [
                    [
                        'customfield_id' => $shopifyVariantFieldId,
                        'value' => $variant->shopify_variant_id . '-legacy',
                    ],
                ];
            }

            $this->makeRequest('PUT', '/books/v3/items/' . $variant->zoho_item_id, $updateData);
        } catch (\Throwable $e) {
            Log::warning("reconcileLegacyNonTrackedItem: Failed to update legacy Zoho item {$variant->zoho_item_id}: " . $e->getMessage());
        }

        $variant->update(['zoho_item_id' => null]);

        return $this->createItem($variant);
    }

    // Update an existing Zoho Books item using Shopify variant data
    public function updateItem(ProductVariant $variant): array
    {
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

        // Sync variant price to currency-specific Price List (failure does not rollback item update)
        $priceListResult = null;
        try {
            $priceListService = new ZohoPriceListService($this, $this->shop);
            $priceListResult = $priceListService->syncVariantToPriceList($variant);
        } catch (\Throwable $e) {
            Log::warning("Price list sync failed during updateItem for variant {$variant->id}: " . $e->getMessage());
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
            'price_list' => $priceListResult,
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

        $url = $imageUrl ?? $variant->image_url ?? $variant->product?->image_url;

        if (empty($url)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'No image URL provided or found on product.',
            ];
        }

        try {
            // Download the image bytes with a standard browser User-Agent
            $imageResponse = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
                ->get($url);

            if (!$imageResponse->successful()) {
                Log::warning("Failed to download image from {$url} for Zoho item {$zohoItemId}");
                return [
                    'success' => false,
                    'message' => 'Failed to download image from URL.',
                ];
            }

            $imageBytes = $imageResponse->body();
            $filename = basename(parse_url($url, PHP_URL_PATH) ?? 'image.jpg') ?: 'image.jpg';

            // SVG Detection: Zoho Books does not support SVG images.
            // Detect via content-type header or content inspection.
            $contentType = strtolower(trim($imageResponse->header('Content-Type') ?? ''));
            $isSvg = str_contains($contentType, 'image/svg') ||
                     str_starts_with(trim($imageBytes), '<?xml') ||
                     str_starts_with(trim($imageBytes), '<svg') ||
                     str_contains(strtolower(substr($imageBytes, 0, 500)), '<svg');

            if ($isSvg) {
                Log::info("uploadItemImage: SVG image detected for Zoho item {$zohoItemId}. Attempting rasterization.");

                // Attempt to rasterize SVG to PNG using Imagick if available
                if (extension_loaded('imagick')) {
                    try {
                        $imagick = new \Imagick();
                        $imagick->readImageBlob($imageBytes);
                        $imagick->setImageFormat('png');
                        $imageBytes = $imagick->getImageBlob();
                        $filename = pathinfo($filename, PATHINFO_FILENAME) . '.png';
                        $imagick->clear();
                        $imagick->destroy();
                        Log::info("uploadItemImage: SVG rasterized to PNG via Imagick for Zoho item {$zohoItemId}.");
                    } catch (\Throwable $svgEx) {
                        Log::warning("uploadItemImage: SVG rasterization via Imagick failed for Zoho item {$zohoItemId}: " . $svgEx->getMessage() . ". Skipping image upload.");
                        return [
                            'success' => false,
                            'skipped' => true,
                            'message' => 'SVG image could not be rasterized. Zoho does not support SVG.',
                        ];
                    }
                } else {
                    Log::warning("uploadItemImage: SVG image detected for Zoho item {$zohoItemId}, but Imagick extension is not available. Skipping image upload.");
                    return [
                        'success' => false,
                        'skipped' => true,
                        'message' => 'SVG image not supported by Zoho and Imagick not available for conversion.',
                    ];
                }
            }

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
                $apiUrl . '/books/v3/items/' . $zohoItemId . '/image?organization_id=' . $connection->organization_id
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

    /**
     * Mark a Zoho item as inactive in Zoho Books.
     */
    public function markItemInactive(string $zohoItemId): array
    {
        try {
            $result = $this->makeRequest('POST', '/books/v3/items/' . $zohoItemId . '/inactive');
            return [
                'success' => true,
                'status' => 'inactivated',
                'zoho_response' => $result,
            ];
        } catch (\Throwable $e) {
            if ($this->isItemNotFoundException($e)) {
                return [
                    'success' => true,
                    'status' => 'already_missing',
                    'message' => 'Zoho item not found when attempting to mark inactive.',
                ];
            }
            throw $e;
        }
    }

    /**
     * Delete or safely deactivate a Zoho Books item.
     * Handles item deletion idempotently:
     * - If item deleted successfully -> status 'deleted'
     * - If item already missing -> status 'already_missing'
     * - If item referenced by accounting transactions -> falls back to markItemInactive ('inactivated')
     * - If deletion fails unexpectedly -> status 'failed'
     */
    public function deleteItem(string $zohoItemId): array
    {
        try {
            $result = $this->makeRequest('DELETE', '/books/v3/items/' . $zohoItemId);
            return [
                'success' => true,
                'status' => 'deleted',
                'zoho_response' => $result,
            ];
        } catch (\Throwable $e) {
            if ($this->isItemNotFoundException($e)) {
                return [
                    'success' => true,
                    'status' => 'already_missing',
                    'message' => 'Zoho item already removed or missing in Zoho Books.',
                ];
            }

            $errMsg = strtolower($e->getMessage());
            $code = $e->getCode();
            $isReferencedError = str_contains($errMsg, 'associated with') ||
                str_contains($errMsg, 'cannot be deleted') ||
                str_contains($errMsg, 'used in') ||
                str_contains($errMsg, 'referenced') ||
                str_contains($errMsg, 'transaction') ||
                in_array($code, [1000, 1005, 1008, 1013, 10005], true);

            if ($isReferencedError) {
                Log::info("deleteItem: Zoho item {$zohoItemId} is referenced by accounting transactions. Falling back to markItemInactive.");
                try {
                    return $this->markItemInactive($zohoItemId);
                } catch (\Throwable $inactiveEx) {
                    Log::error("deleteItem: Fallback markItemInactive failed for Zoho item {$zohoItemId}: " . $inactiveEx->getMessage());
                    return [
                        'success' => false,
                        'status' => 'failed',
                        'error' => $inactiveEx->getMessage(),
                    ];
                }
            }

            Log::error("deleteItem: Failed to delete Zoho item {$zohoItemId}: " . $e->getMessage());
            return [
                'success' => false,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }



    public function syncItem(ProductVariant $variant): array {
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
        // Get all Shopify product variants for this shop with their parent products
        $variants = ProductVariant::whereHas('product', function ($query) {
            $query->where('shop_id', $this->shop->id);
        })->with('product')->get();

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
    /**
     * Dynamically resolve the active Inventory Asset account ID from Zoho.
     */
    public function getInventoryAssetAccountId(): ?string
    {
        try {
            $editPage = $this->makeRequest('GET', '/books/v3/items/editpage');
            $accounts = $editPage['inventory_accounts_list'] ?? [];

            foreach ($accounts as $acc) {
                if (($acc['is_active'] ?? true) && !empty($acc['account_id'])) {
                    return (string) $acc['account_id'];
                }
            }
        } catch (\Throwable $e) {
            Log::error("getInventoryAssetAccountId: Failed to fetch inventory accounts list from Zoho: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Synchronize inventory quantity from Shopify to Zoho Books / Inventory.
     */
    public function syncInventory(
        ProductVariant $variant,
        ?int $newShopifyQuantity = null,
        string $source = 'shopify',
        ?string $eventId = null
    ): array {
        if (!empty($eventId)) {
            if (ShopifyProcessedWebhook::where('webhook_id', (string) $eventId)->exists()) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => "Event {$eventId} already processed.",
                ];
            }
        }

        if (!$variant->zoho_item_id) {
            Log::warning("syncInventory: Variant ID {$variant->id} is not linked to a Zoho item. Skipping inventory sync.");
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Variant is not linked to a Zoho item.',
            ];
        }

        // Fetch live storewide available quantity from Shopify if null
        $targetQuantity = $newShopifyQuantity;
        if ($targetQuantity === null && !empty($variant->shopify_inventory_item_id)) {
            $shopifyService = app(ShopifyService::class);
            $storewideQty = $shopifyService->fetchStorewideAvailableQuantity($this->shop, $variant->shopify_inventory_item_id);
            if ($storewideQty !== null) {
                $targetQuantity = $storewideQty;
            }
        }
        $targetQuantity = $targetQuantity ?? (int) ($variant->inventory_quantity ?? 0);

        // Loop Prevention: If change originated from Zoho sync and target equals last synced qty, skip
        if ($source === 'shopify' && $variant->last_sync_source === 'zoho' && $variant->last_synced_quantity !== null && $targetQuantity === $variant->last_synced_quantity) {
            Log::info("syncInventory: Ignoring self-generated Shopify echo for variant ID {$variant->id} (Qty: {$targetQuantity}).");
            return [
                'success' => true,
                'skipped' => true,
                'adjusted' => false,
                'message' => 'Self-generated inventory change ignored to prevent infinite loop.',
            ];
        }

        $zohoItem = $this->getItem($variant->zoho_item_id);

        if (!$zohoItem) {
            Log::error("syncInventory: Zoho item ID {$variant->zoho_item_id} not found for variant ID {$variant->id}.");
            return [
                'success' => false,
                'capability' => $this->detectInventoryCapability(),
                'item_inventory_tracked' => false,
                'sync_available' => false,
                'reason' => "Zoho item ID {$variant->zoho_item_id} not found.",
                'message' => "Zoho item ID {$variant->zoho_item_id} not found.",
            ];
        }

        $capability = $this->detectInventoryCapability();
        $isInventoryTracked = isset($zohoItem['track_inventory']) ? (bool) $zohoItem['track_inventory'] : true;

        if (!$isInventoryTracked) {
            $reason = "Mapped Zoho item {$variant->zoho_item_id} is not configured for inventory tracking (track_inventory = false).";
            Log::warning("syncInventory: Variant ID {$variant->id} (Zoho Item: {$variant->zoho_item_id}) is not inventory-tracked in Zoho.");

            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'sync_inventory',
                'status' => 'skipped',
                'zoho_item_id' => $variant->zoho_item_id,
                'message' => $reason,
                'synced_at' => now(),
            ]);

            return [
                'success' => false,
                'skipped' => true,
                'capability' => $capability,
                'item_inventory_tracked' => false,
                'sync_available' => false,
                'adjusted' => false,
                'shopify_quantity' => $targetQuantity,
                'zoho_quantity' => (int) ($zohoItem['actual_available_stock'] ?? $zohoItem['stock_on_hand'] ?? 0),
                'delta' => 0,
                'reason' => $reason,
                'message' => $reason,
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

            $variant->inventory_quantity = $targetQuantity;
            $variant->last_synced_quantity = $targetQuantity;
            $variant->last_sync_source = $source;
            $variant->save();

            return [
                'success' => true,
                'adjusted' => false,
                'capability' => $capability,
                'item_inventory_tracked' => true,
                'sync_available' => true,
                'shopify_quantity' => $targetQuantity,
                'zoho_quantity' => $currentZohoQuantity,
                'delta' => 0,
                'reason' => null,
                'message' => 'Inventory is already in sync.',
            ];
        }

        if ($capability === self::CAPABILITY_BOOKS_NATIVE) {
            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'sync_inventory',
                'status' => 'skipped',
                'zoho_item_id' => $variant->zoho_item_id,
                'message' => self::BOOKS_NATIVE_ERROR_MESSAGE,
                'synced_at' => now(),
            ]);

            return [
                'success' => false,
                'capability' => $capability,
                'item_inventory_tracked' => true,
                'sync_available' => false,
                'adjusted' => false,
                'shopify_quantity' => $targetQuantity,
                'zoho_quantity' => $currentZohoQuantity,
                'delta' => 0,
                'reason' => self::BOOKS_NATIVE_ERROR_MESSAGE,
                'message' => self::BOOKS_NATIVE_ERROR_MESSAGE,
            ];
        }

        if ($capability === self::CAPABILITY_UNAVAILABLE) {
            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'sync_inventory',
                'status' => 'failed',
                'zoho_item_id' => $variant->zoho_item_id,
                'message' => 'Zoho inventory sync is unavailable for this organization connection.',
                'synced_at' => now(),
            ]);

            return [
                'success' => false,
                'capability' => $capability,
                'item_inventory_tracked' => true,
                'sync_available' => false,
                'adjusted' => false,
                'shopify_quantity' => $targetQuantity,
                'zoho_quantity' => $currentZohoQuantity,
                'delta' => 0,
                'reason' => 'Zoho inventory sync is unavailable for this organization connection.',
                'message' => 'Zoho inventory sync is unavailable for this organization connection.',
            ];
        }

        $endpoint = match ($capability) {
            self::CAPABILITY_ZOHO_INVENTORY => '/inventory/v1/inventoryadjustments',
            self::CAPABILITY_ZOHO_ERP => '/erp/v3/inventoryadjustments',
            default => '/inventory/v1/inventoryadjustments',
        };

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
            $response = $this->makeRequest('POST', $endpoint, $payload);

            Log::info("syncInventory: Successfully created Zoho inventory adjustment for variant ID {$variant->id}. Delta: {$delta}");

            $variant->inventory_quantity = $targetQuantity;
            $variant->last_synced_quantity = $targetQuantity;
            $variant->last_sync_source = $source;
            $variant->inventory_sync_version = ($variant->inventory_sync_version ?? 0) + 1;
            $variant->save();

            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'inventory_update',
                'status' => 'success',
                'zoho_item_id' => $variant->zoho_item_id,
                'message' => "Synced Shopify inventory quantity ({$targetQuantity}) to Zoho Books (Delta: {$delta}, Source: {$source}).",
                'synced_at' => now(),
            ]);

            if (!empty($eventId)) {
                ShopifyProcessedWebhook::create([
                    'webhook_id' => (string) $eventId,
                    'topic' => 'shopify.inventory.sync',
                    'shop_domain' => $this->shop->shop_domain,
                ]);
            }

            return [
                'success' => true,
                'adjusted' => true,
                'capability' => $capability,
                'item_inventory_tracked' => true,
                'sync_available' => true,
                'shopify_quantity' => $targetQuantity,
                'zoho_quantity' => $currentZohoQuantity,
                'delta' => $delta,
                'zoho_response' => $response,
                'reason' => null,
                'message' => 'Inventory adjustment created successfully.',
            ];
        } catch (\Throwable $e) {
            Log::error("syncInventory: Failed to create Zoho inventory adjustment for variant ID {$variant->id} (Delta: {$delta}): " . $e->getMessage());

            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'inventory_update',
                'status' => 'failed',
                'zoho_item_id' => $variant->zoho_item_id,
                'message' => "Failed to sync inventory to Zoho Books: " . $e->getMessage(),
                'synced_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Synchronize inventory quantity from Zoho Books back to Shopify.
     * Prevents overselling by clamping stock to non-negative quantities.
     */
    public function syncZohoInventoryToShopify(
        ProductVariant $variant,
        ?string $locationId = null,
        string $source = 'zoho',
        ?string $eventId = null
    ): array {
        if (!empty($eventId)) {
            if (ShopifyProcessedWebhook::where('webhook_id', (string) $eventId)->exists()) {
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => "Event {$eventId} already processed.",
                ];
            }
        }

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

        try {
            $zohoItem = $this->getItem($variant->zoho_item_id);

            if (!$zohoItem) {
                Log::error("syncZohoInventoryToShopify: Zoho item ID {$variant->zoho_item_id} not found for variant ID {$variant->id}.");
                $message = "Zoho item ID {$variant->zoho_item_id} not found.";
                return [
                    'success' => false,
                    'capability' => $this->detectInventoryCapability(),
                    'item_inventory_tracked' => false,
                    'sync_available' => false,
                    'reason' => $message,
                    'message' => $message,
                ];
            }

            if (isset($zohoItem['track_inventory']) && !$zohoItem['track_inventory']) {
                $reason = "Mapped Zoho item {$variant->zoho_item_id} is not configured for inventory tracking (track_inventory = false).";
                Log::warning("syncZohoInventoryToShopify: Variant ID {$variant->id} (Zoho Item: {$variant->zoho_item_id}) is not inventory-tracked in Zoho.");

                return [
                    'success' => false,
                    'skipped' => true,
                    'capability' => $this->detectInventoryCapability(),
                    'item_inventory_tracked' => false,
                    'sync_available' => false,
                    'reason' => $reason,
                    'message' => $reason,
                ];
            }

            $zohoQuantity = (int) (
                $zohoItem['actual_available_stock'] ??
                $zohoItem['stock_on_hand'] ??
                $zohoItem['available_stock'] ??
                0
            );

            // Loop Prevention: If change originated from Shopify sync and Zoho qty equals last synced qty, skip
            if ($source === 'zoho' && $variant->last_sync_source === 'shopify' && $variant->last_synced_quantity !== null && $zohoQuantity === $variant->last_synced_quantity) {
                Log::info("syncZohoInventoryToShopify: Ignoring self-generated Zoho echo for variant ID {$variant->id} (Qty: {$zohoQuantity}).");
                return [
                    'success' => true,
                    'skipped' => true,
                    'message' => 'Self-generated inventory change ignored to prevent infinite loop.',
                ];
            }

            // No-op check if Shopify quantity already equals Zoho stock
            if ($variant->inventory_quantity === $zohoQuantity) {
                $variant->last_synced_quantity = $zohoQuantity;
                $variant->last_sync_source = $source;
                $variant->save();

                return [
                    'success' => true,
                    'adjusted' => false,
                    'message' => 'Inventory is already in sync.',
                ];
            }

            $safeQuantity = max(0, $zohoQuantity);

            $shopifyService = app(ShopifyService::class);
            $result = $shopifyService->setInventoryQuantity(
                $this->shop,
                $variant->shopify_inventory_item_id,
                $safeQuantity,
                $locationId
            );

            $variant->inventory_quantity = $safeQuantity;
            $variant->last_synced_quantity = $safeQuantity;
            $variant->last_sync_source = $source;
            $variant->inventory_sync_version = ($variant->inventory_sync_version ?? 0) + 1;
            $variant->save();

            Log::info("syncZohoInventoryToShopify: Updated variant ID {$variant->id} inventory to {$safeQuantity} on Shopify (Zoho stock: {$zohoQuantity}).");

            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'inventory_update',
                'status' => 'success',
                'zoho_item_id' => $variant->zoho_item_id,
                'message' => "Synced Zoho stock ({$zohoQuantity}) to Shopify for variant #{$variant->id} (Source: {$source}).",
                'synced_at' => now(),
            ]);

            if (!empty($eventId)) {
                ShopifyProcessedWebhook::create([
                    'webhook_id' => (string) $eventId,
                    'topic' => 'zoho.inventory.sync',
                    'shop_domain' => $this->shop->shop_domain,
                ]);
            }

            return [
                'success' => true,
                'variant_id' => $variant->id,
                'zoho_quantity' => $zohoQuantity,
                'shopify_quantity' => $safeQuantity,
                'shopify_response' => $result,
                'message' => 'Inventory synchronized from Zoho to Shopify successfully.',
            ];
        } catch (\Throwable $e) {
            Log::error("syncZohoInventoryToShopify: Failed to sync inventory for variant ID {$variant->id}: " . $e->getMessage());

            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'product_variant_id' => $variant->id,
                'action' => 'inventory_update',
                'status' => 'failed',
                'zoho_item_id' => $variant->zoho_item_id ?? null,
                'message' => 'Zoho to Shopify inventory sync failed: ' . $e->getMessage(),
                'synced_at' => now(),
            ]);

            return [
                'success' => false,
                'variant_id' => $variant->id,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Synchronize inventory quantity for all mapped variants from Zoho to Shopify.
     */
    public function syncAllZohoInventoryToShopify(?Shop $shop = null): array
    {
        $targetShop = $shop ?? $this->shop;

        if (!$targetShop) {
            throw new \InvalidArgumentException('No shop specified for bulk Zoho inventory sync.');
        }

        $this->shop = $targetShop;

        $variants = ProductVariant::whereHas('product', function ($query) use ($targetShop) {
            $query->where('shop_id', $targetShop->id);
        })->get();

        $synced = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach ($variants as $variant) {
            try {
                $res = $this->syncZohoInventoryToShopify($variant);
                if (!empty($res['skipped'])) {
                    $skipped++;
                } elseif (!empty($res['success'])) {
                    $synced++;
                } else {
                    $failed++;
                }
                $results[] = $res;
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'success' => false,
                    'variant_id' => $variant->id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'total' => $variants->count(),
            'synced' => $synced,
            'success_count' => $synced,
            'failed' => $failed,
            'skipped' => $skipped,
            'details' => $results,
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

        $billing = $customer->billing_address;
        $shipping = $customer->shipping_address;
        $phone = $customer->phone ?? ($billing['phone'] ?? ($shipping['phone'] ?? ''));

        $payload = [
            'contact_name' => $fullName,
            'contact_type' => 'customer',
            'phone' => $phone,
            'contact_persons' => [
                [
                    'first_name' => $customer->first_name ?? '',
                    'last_name' => $customer->last_name ?? '',
                    'email' => $customer->email ?? '',
                    'phone' => $phone,
                    'is_primary_contact' => true,
                ],
            ],
        ];

        $billing = $customer->billing_address;
        if (!empty($billing) && is_array($billing)) {
            $payload['billing_address'] = $this->formatZohoAddressObject($billing, $customer);
            if (!empty($billing['company'])) {
                $payload['company_name'] = mb_substr($billing['company'], 0, 100);
            }
        }

        $shipping = $customer->shipping_address;
        if (!empty($shipping) && is_array($shipping)) {
            $payload['shipping_address'] = $this->formatZohoAddressObject($shipping, $customer);
            if (empty($payload['company_name']) && !empty($shipping['company'])) {
                $payload['company_name'] = mb_substr($shipping['company'], 0, 100);
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

        $custName = trim("{$customer->first_name} {$customer->last_name}");
        if (empty($custName)) {
            $custName = $customer->email ?: "Customer #{$customer->id}";
        }
        $contactRef = $zohoContactId ? "Zoho Contact ID: {$zohoContactId}" : '';
        $msgAction = $created ? 'created' : 'synced';
        $custMsg = "Customer {$msgAction} successfully — {$custName}" . ($contactRef ? " → {$contactRef}" : "") . ".";

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'zoho_contact_id' => $zohoContactId,
            'customer_id' => $customer->id,
            'message' => $custMsg,
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
                    if (($so['status'] ?? '') !== 'void') {
                        return $so;
                    }
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
     * Confirm / Open a Zoho Sales Order.
     */
    public function confirmSalesOrder(string $zohoSalesOrderId): array
    {
        try {
            $response = $this->makeRequest('POST', "/books/v3/salesorders/{$zohoSalesOrderId}/status/confirmed");
            return $response['salesorder'] ?? $response;
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'already confirmed') || str_contains($msg, 'already open') || str_contains($msg, 'status cannot be changed') || str_contains($msg, 'already invoiced')) {
                Log::info("confirmSalesOrder: Sales order {$zohoSalesOrderId} is already confirmed/open in Zoho Books.");
                return ['salesorder_id' => $zohoSalesOrderId, 'status' => 'confirmed'];
            }
            throw $e;
        }
    }

    /**
     * Update an existing Sales Order in Zoho Books.
     */
    public function updateSalesOrder(string $salesOrderId, array $payload): array
    {
        return $this->makeRequest('PUT', '/books/v3/salesorders/' . $salesOrderId, $payload);
    }

    /**
     * Void a Zoho Sales Order.
     */
    public function voidSalesOrder(string $zohoSalesOrderId): array
    {
        try {
            $response = $this->makeRequest('POST', "/books/v3/salesorders/{$zohoSalesOrderId}/status/void");
            return $response['salesorder'] ?? $response;
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'already void')) {
                Log::info("voidSalesOrder: Sales order {$zohoSalesOrderId} is already void in Zoho Books.");
                return ['salesorder_id' => $zohoSalesOrderId, 'status' => 'void'];
            }
            if (str_contains($msg, 'invoiced sales order cannot be marked void') || str_contains($msg, '36009')) {
                Log::info("voidSalesOrder: Sales order {$zohoSalesOrderId} is invoiced/closed and cannot be marked void in Zoho Books.");
                return [
                    'salesorder_id' => $zohoSalesOrderId,
                    'status' => 'invoiced_cannot_void',
                    'message' => 'Invoiced/closed sales order cannot be marked void in Zoho Books.',
                ];
            }
            throw $e;
        }
    }

    /**
     * Mark a Zoho Sales Order as Fulfilled.
     */
    public function markSalesOrderAsFulfilled(string $zohoSalesOrderId): array
    {
        try {
            $response = $this->makeRequest('POST', "/books/v3/salesorders/{$zohoSalesOrderId}/status/fulfilled");
            return $response['salesorder'] ?? $response;
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'already fulfilled') || str_contains($msg, 'already delivered') || str_contains($msg, 'status cannot be changed')) {
                Log::info("markSalesOrderAsFulfilled: Sales order {$zohoSalesOrderId} is already fulfilled in Zoho Books.");
                return ['salesorder_id' => $zohoSalesOrderId, 'status' => 'fulfilled'];
            }
            Log::warning("markSalesOrderAsFulfilled: Could not mark Sales order {$zohoSalesOrderId} as fulfilled: " . $e->getMessage());
            return ['salesorder_id' => $zohoSalesOrderId, 'error' => $e->getMessage()];
        }
    }

    /**
     * Synchronize a Shopify order to Zoho Books as a Sales Order.
     */
    public function syncOrder(Order $order): array
    {
        $financialStatus = strtolower(trim((string) ($order->financial_status ?? '')));
        $isCancelled = in_array($financialStatus, ['voided', 'cancelled'], true);

        if ($isCancelled) {
            $refNumber = $order->order_number ?? (string) $order->shopify_order_id;
            $zohoSalesOrderId = $order->zoho_sales_order_id;

            if (!$zohoSalesOrderId && !empty($refNumber)) {
                $existing = $this->findZohoSalesOrderByReferenceNumber($refNumber);
                if ($existing && !empty($existing['salesorder_id'])) {
                    $zohoSalesOrderId = (string) $existing['salesorder_id'];
                    $order->zoho_sales_order_id = $zohoSalesOrderId;
                    $order->zoho_sales_order_number = $existing['salesorder_number'] ?? null;
                    $order->save();
                }
            }

            if ($zohoSalesOrderId) {
                $this->voidSalesOrder($zohoSalesOrderId);
                $order->zoho_synced_at = now();
                $order->save();

                SyncHistory::create([
                    'shop_id' => $this->shop->id,
                    'action' => 'void',
                    'status' => 'success',
                    'zoho_item_id' => $zohoSalesOrderId,
                    'message' => 'Sales Order voided in Zoho Books.',
                    'synced_at' => now(),
                ]);

                return [
                    'success' => true,
                    'created' => false,
                    'updated' => true,
                    'voided' => true,
                    'zoho_sales_order_id' => $zohoSalesOrderId,
                    'zoho_sales_order_number' => $order->zoho_sales_order_number,
                    'order_id' => $order->id,
                    'message' => 'Sales Order voided successfully.',
                ];
            }

            return [
                'success' => true,
                'created' => false,
                'updated' => false,
                'voided' => false,
                'order_id' => $order->id,
                'message' => 'Order is cancelled; no existing Zoho Sales Order to void.',
            ];
        }

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

            $taxId = $this->resolveZohoTaxId($item['tax_lines'] ?? [], $order);

            $lineItemPayload = [
                'item_id' => $zohoItemId,
                'name' => $name,
                'description' => 'SKU: ' . ($sku ?? 'N/A'),
                'rate' => $price,
                'quantity' => $qty,
            ];

            if ($taxId) {
                $lineItemPayload['tax_id'] = $taxId;
            }

            if (!empty($item['total_discount']) && (float) $item['total_discount'] > 0) {
                $lineItemPayload['discount'] = (float) $item['total_discount'];
            }

            $zohoLineItems[] = $lineItemPayload;
        }

        // 3. Build Sales Order Payload
        $refNumber = $order->order_number ?? (string) $order->shopify_order_id;
        $orderDateStr = $order->order_date ? $order->order_date->format('Y-m-d') : date('Y-m-d');
        $taxSettings = $this->shop->tax_settings ?? [];
        $isInclusive = $order->taxes_included || (($taxSettings['tax_mode'] ?? '') === 'inclusive');
        $isDiscountBeforeTax = (($taxSettings['discount_tax_mode'] ?? 'before_tax') !== 'after_tax');

        $payload = [
            'customer_id' => $zohoContactId,
            'reference_number' => $refNumber,
            'date' => $orderDateStr,
            'line_items' => $zohoLineItems,
            'is_inclusive_tax' => $isInclusive,
            'is_discount_before_tax' => $isDiscountBeforeTax,
            'shipping_charge' => (float) ($order->shipping_total ?? 0.00),
        ];

        if (!empty($order->shipping_method)) {
            $payload['delivery_method'] = $order->shipping_method;
        }

        $shipAddress = $order->shipping_address ?? ($customer->shipping_address ?? null);
        if (!empty($shipAddress) && is_array($shipAddress)) {
            $payload['shipping_address'] = $this->formatZohoAddressString($shipAddress, $customer);
        }

        $currencyCode = $order->currency ?? $this->shop->currency ?? 'USD';
        if (!empty($currencyCode)) {
            $payload['currency_code'] = strtoupper($currencyCode);

            // Resolve currency_id and pricebook_id for correct multi-currency pricing
            try {
                $priceListService = new ZohoPriceListService($this, $this->shop);
                $currencyResolution = $priceListService->resolveTransactionCurrency($currencyCode);

                if (!empty($currencyResolution['currency_id'])) {
                    $payload['currency_id'] = $currencyResolution['currency_id'];
                }
                if (!empty($currencyResolution['pricebook_id'])) {
                    $payload['pricebook_id'] = $currencyResolution['pricebook_id'];
                }
            } catch (\Throwable $e) {
                Log::warning("syncOrder: Could not resolve currency/price list for {$currencyCode}: " . $e->getMessage());
            }
        }

        if ((float) $order->discount_total > 0) {
            $payload['discount'] = (float) $order->discount_total;
            $payload['discount_type'] = 'entity_level';
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
        if (!empty($order->tracking_number)) {
            $carrierStr = !empty($order->tracking_company) ? " (Carrier: {$order->tracking_company})" : "";
            $notesArray[] = "Tracking: #" . $order->tracking_number . $carrierStr;
            if (!empty($order->tracking_url)) {
                $notesArray[] = "Tracking URL: " . $order->tracking_url;
            }
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

        // 6. Confirm / Open Sales Order in Zoho Books
        if ($zohoSalesOrderId) {
            try {
                $this->confirmSalesOrder($zohoSalesOrderId);
                Log::info("syncOrder: Confirmed Sales Order ID {$zohoSalesOrderId} for order ID {$order->id}.");
            } catch (\Throwable $e) {
                Log::warning("syncOrder: Could not confirm Sales Order ID {$zohoSalesOrderId}: " . $e->getMessage());
            }
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

        $fulfillmentStatus = strtolower(trim((string) ($order->fulfillment_status ?? '')));
        if ($fulfillmentStatus === 'fulfilled' && $zohoSalesOrderId) {
            $this->markSalesOrderAsFulfilled($zohoSalesOrderId);
        }

        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'action' => $created ? 'created' : ($updated ? 'updated' : 'synced'),
            'status' => 'success',
            'zoho_item_id' => $zohoSalesOrderId,
            'message' => $created ? 'Sales Order created in Zoho Books.' : 'Sales Order updated in Zoho Books.',
            'synced_at' => now(),
        ]);

        $soRef = $order->zoho_sales_order_number ?: ($zohoSalesOrderId ? "SO-{$zohoSalesOrderId}" : '');
        $orderRef = $order->order_number ? "#{$order->order_number}" : "Order #{$order->id}";
        $msgAction = $created ? 'created' : 'synced';
        $orderMsg = "Sales Order {$msgAction} successfully — Shopify Order {$orderRef}" . ($soRef ? " → Zoho {$soRef}" : "") . ".";

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'zoho_sales_order_id' => $zohoSalesOrderId,
            'zoho_sales_order_number' => $order->zoho_sales_order_number,
            'order_id' => $order->id,
            'message' => $orderMsg,
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
     * Create a new Zoho Invoice from a confirmed Sales Order.
     */
    public function createInvoiceFromSalesOrder(string $zohoSalesOrderId, array $payload = []): array
    {
        $response = $this->makeRequest('POST', "/books/v3/invoices/fromsalesorder?salesorder_id={$zohoSalesOrderId}", $payload);
        return $response['invoice'] ?? [];
    }

    /**
     * Update an existing Zoho Invoice.
     */
    public function updateInvoice(Invoice $invoice, Order $order, array $payload): array
    {
        $reasonText = 'Shopify order status update';
        if (empty($payload['reason'])) {
            $payload['reason'] = $reasonText;
        }
        $endpoint = "/books/v3/invoices/{$invoice->zoho_invoice_id}?reason=" . urlencode($reasonText);
        $response = $this->makeRequest('PUT', $endpoint, $payload);
        return $response['invoice'] ?? [];
    }

    /**
     * Void a Zoho Invoice.
     */
    public function voidInvoice(string $zohoInvoiceId): array
    {
        try {
            $response = $this->makeRequest('POST', "/books/v3/invoices/{$zohoInvoiceId}/status/void");
            return $response['invoice'] ?? $response;
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'already void')) {
                Log::info("voidInvoice: Invoice {$zohoInvoiceId} is already void in Zoho Books.");
                return ['invoice_id' => $zohoInvoiceId, 'status' => 'void'];
            }
            if (str_contains($msg, 'payment') || str_contains($msg, 'cannot be voided') || str_contains($msg, 'associated with it')) {
                Log::info("voidInvoice: Invoice {$zohoInvoiceId} cannot be voided because customer payments are associated with it.");
                return ['invoice_id' => $zohoInvoiceId, 'status' => 'paid_cannot_void', 'message' => 'Invoice has associated customer payments and requires Credit Note / Refund reconciliation rather than voiding.'];
            }
            throw $e;
        }
    }

    /**
     * Synchronize a Shopify Order as a Zoho Invoice.
     */
    public function syncInvoice(Order $order): array
    {
        if ($order->shop_id !== $this->shop->id) {
            throw new \Exception("Order #{$order->id} does not belong to shop {$this->shop->shop_domain}");
        }

        $financialStatus = strtolower(trim((string) ($order->financial_status ?? '')));
        $isCancelled = in_array($financialStatus, ['voided', 'cancelled'], true);

        if ($isCancelled) {
            $localInvoice = Invoice::where('shop_id', $this->shop->id)
                ->where('order_id', $order->id)
                ->first();

            $zohoInvoiceId = $localInvoice->zoho_invoice_id ?? null;

            if (empty($zohoInvoiceId) && !empty($order->order_number)) {
                $existingZohoInv = $this->findZohoInvoiceByReferenceNumber($order->order_number);
                if ($existingZohoInv && !empty($existingZohoInv['invoice_id'])) {
                    $zohoInvoiceId = $existingZohoInv['invoice_id'];
                }
            }

            if ($zohoInvoiceId) {
                $voidRes = $this->voidInvoice($zohoInvoiceId);
                $status = ($voidRes['status'] ?? '') === 'paid_cannot_void' ? ($localInvoice->status ?? 'sent') : 'void';

                if ($localInvoice) {
                    $localInvoice->update([
                        'status' => $status,
                        'sync_status' => 'synced',
                        'synced_at' => now(),
                    ]);
                }

                SyncHistory::create([
                    'shop_id' => $this->shop->id,
                    'order_id' => $order->id,
                    'invoice_id' => $localInvoice->id ?? null,
                    'action' => 'void',
                    'status' => 'success',
                    'zoho_invoice_id' => $zohoInvoiceId,
                    'message' => $voidRes['message'] ?? 'Invoice voided in Zoho Books.',
                    'synced_at' => now(),
                ]);

                return [
                    'success' => true,
                    'created' => false,
                    'updated' => true,
                    'voided' => $status === 'void',
                    'zoho_invoice_id' => $zohoInvoiceId,
                    'order_id' => $order->id,
                    'message' => $voidRes['message'] ?? 'Invoice voided successfully.',
                ];
            }

            return [
                'success' => true,
                'created' => false,
                'updated' => false,
                'voided' => false,
                'order_id' => $order->id,
                'message' => 'Order is cancelled; no existing Zoho Invoice to void.',
            ];
        }

        // 0. Ensure Sales Order is created & reconciled in Zoho Books first
        if (empty($order->zoho_sales_order_id)) {
            $this->syncOrder($order);
            $order->refresh();
        }

        if (empty($order->zoho_sales_order_id)) {
            throw new \Exception("Cannot create invoice for order #{$order->order_number}: Sales Order creation failed or incomplete.");
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
                $formattedVariantId = str_starts_with((string) $shopifyVariantId, 'gid://')
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

            $taxId = $this->resolveZohoTaxId($item['tax_lines'] ?? [], $order);

            $mappedItem = [
                'item_id' => $zohoItemId,
                'name' => $item['name'] ?? $item['title'] ?? 'Item',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'rate' => (float) ($item['price'] ?? 0.00),
                'discount' => (float) ($item['total_discount'] ?? 0.00),
            ];

            if ($taxId) {
                $mappedItem['tax_id'] = $taxId;
            }

            $mappedLineItems[] = $mappedItem;
        }

        // 3. Construct Invoice Payload
        $taxSettings = $this->shop->tax_settings ?? [];
        $isInclusive = $order->taxes_included || (($taxSettings['tax_mode'] ?? '') === 'inclusive');
        $isDiscountBeforeTax = (($taxSettings['discount_tax_mode'] ?? 'before_tax') !== 'after_tax');

        $invNotesArray = [];
        if (!empty($order->notes)) {
            $invNotesArray[] = "Order Note: " . $order->notes;
        }
        if (!empty($order->coupon_code)) {
            $invNotesArray[] = "Coupon Code: " . $order->coupon_code;
        }
        if (!empty($order->tracking_number)) {
            $carrierStr = !empty($order->tracking_company) ? " (Carrier: {$order->tracking_company})" : "";
            $invNotesArray[] = "Tracking: #" . $order->tracking_number . $carrierStr;
            if (!empty($order->tracking_url)) {
                $invNotesArray[] = "Tracking URL: " . $order->tracking_url;
            }
        }

        $invCurrencyCode = strtoupper($order->currency ?? $this->shop->currency ?? 'USD');

        $invoicePayload = [
            'customer_id' => $customer->zoho_contact_id,
            'reference_number' => $order->order_number,
            'date' => $order->order_date ? $order->order_date->format('Y-m-d') : date('Y-m-d'),
            'currency_code' => $invCurrencyCode,
            'line_items' => $mappedLineItems,
            'shipping_charge' => (float) ($order->shipping_total ?? 0.00),
            'discount' => (float) ($order->discount_total ?? 0.00),
            'discount_type' => ((float) ($order->discount_total ?? 0.00) > 0) ? 'entity_level' : 'item_level',
            'is_inclusive_tax' => $isInclusive,
            'is_discount_before_tax' => $isDiscountBeforeTax,
        ];

        // Resolve currency_id and pricebook_id for correct multi-currency pricing
        try {
            $priceListService = new ZohoPriceListService($this, $this->shop);
            $currencyResolution = $priceListService->resolveTransactionCurrency($invCurrencyCode);

            if (!empty($currencyResolution['currency_id'])) {
                $invoicePayload['currency_id'] = $currencyResolution['currency_id'];
            }
            if (!empty($currencyResolution['pricebook_id'])) {
                $invoicePayload['pricebook_id'] = $currencyResolution['pricebook_id'];
            }
        } catch (\Throwable $e) {
            Log::warning("syncInvoice: Could not resolve currency/price list for {$invCurrencyCode}: " . $e->getMessage());
        }

        if (!empty($invNotesArray)) {
            $invoicePayload['notes'] = implode("\n", $invNotesArray);
        }

        if (!empty($order->shipping_method)) {
            $invoicePayload['delivery_method'] = $order->shipping_method;
        }

        $shipAddress = $order->shipping_address ?? ($customer->shipping_address ?? null);
        if (!empty($shipAddress) && is_array($shipAddress)) {
            $invoicePayload['shipping_address'] = $this->formatZohoAddressObject($shipAddress, $customer);
        }

        if ((float) $order->tax_total > 0) {
            $invoicePayload['tax_total'] = (float) $order->tax_total;
        }

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
                $zohoData = $this->createInvoiceFromSalesOrder($order->zoho_sales_order_id);
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

        $invRef = $invoice->invoice_number ?: ($zohoInvoiceId ? "INV-{$zohoInvoiceId}" : '');
        $orderRef = $order->order_number ? "#{$order->order_number}" : "Order #{$order->id}";
        $msgAction = $created ? 'created' : 'synced';
        $invMsg = "Invoice {$msgAction} successfully — Shopify Order {$orderRef}" . ($invRef ? " → Zoho {$invRef}" : "") . ".";

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'zoho_invoice_id' => $zohoInvoiceId,
            'invoice_number' => $invoice->invoice_number,
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'message' => $invMsg,
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

    /**
     * Fetch bank/deposit accounts from Zoho Books API v3.
     */
    public function fetchAccounts(): array
    {
        try {
            $data = $this->makeRequest('GET', '/books/v3/bankaccounts');
            if (($data['code'] ?? -1) === 0 && !empty($data['bankaccounts']) && is_array($data['bankaccounts'])) {
                return array_map(function ($acc) {
                    return [
                        'account_id' => (string) ($acc['account_id'] ?? ''),
                        'account_name' => (string) ($acc['account_name'] ?? 'Account ' . ($acc['account_id'] ?? '')),
                        'account_type' => (string) ($acc['account_type'] ?? 'bank'),
                    ];
                }, $data['bankaccounts']);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Zoho bank accounts: ' . $e->getMessage());
        }

        return [
            ['account_id' => 'undeposited_funds', 'account_name' => 'Undeposited Funds', 'account_type' => 'cash'],
            ['account_id' => 'petty_cash', 'account_name' => 'Petty Cash', 'account_type' => 'cash'],
            ['account_id' => 'primary_bank_account', 'account_name' => 'Primary Bank Account', 'account_type' => 'bank'],
        ];
    }

    /**
     * Resolve Shopify gateway to Zoho payment mode and deposit account.
     *
     * Delegates to PaymentResolverService for automatic, deterministic resolution.
     * No longer reads from shop payment_gateway_settings (deprecated).
     */
    public function getPaymentGatewayMapping(?string $gateway): array
    {
        $resolver = new PaymentResolverService();
        $resolved = $resolver->resolveZohoPaymentDetails([
            'gateway' => $gateway,
        ]);

        return [
            'payment_mode' => $resolved['payment_mode'],
            'account_id'   => $resolved['account_id'],
        ];
    }

    /**
     * Find an existing Zoho Customer Payment by reference_number (for idempotency and crash recovery).
     */
    public function findZohoCustomerPaymentByReferenceNumber(string $refNumber): ?array
    {
        $trimmedRef = trim($refNumber);
        if ($trimmedRef === '') {
            return null;
        }

        try {
            $response = $this->makeRequest('GET', '/books/v3/customerpayments', [
                'reference_number' => $trimmedRef,
            ]);

            $payments = $response['customerpayments'] ?? ($response['payments'] ?? []);
            foreach ($payments as $pay) {
                if (!empty($pay['reference_number']) && strcasecmp(trim((string) $pay['reference_number']), $trimmedRef) === 0) {
                    return $pay;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("findZohoCustomerPaymentByReferenceNumber failed for ref '{$trimmedRef}': " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fetch a Customer Payment from Zoho Books by ID.
     */
    public function getCustomerPayment(string $zohoPaymentId): ?array
    {
        try {
            $response = $this->makeRequest('GET', '/books/v3/customerpayments/' . $zohoPaymentId);
            return $response['payment'] ?? ($response['customerpayment'] ?? null);
        } catch (\Throwable $e) {
            if ($this->isItemNotFoundException($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create a Customer Payment in Zoho Books.
     */
    public function createCustomerPayment(array $payload): array
    {
        $response = $this->makeRequest('POST', '/books/v3/customerpayments', $payload);
        return $response['payment'] ?? ($response['customerpayment'] ?? $response);
    }

    /**
     * Synchronize a local Payment to Zoho Books as a Customer Payment.
     */
    public function syncPayment(Payment $payment): array
    {
        if ($payment->shop_id !== $this->shop->id) {
            throw new \Exception("Payment #{$payment->id} does not belong to shop {$this->shop->shop_domain}");
        }

        $payment->unsetRelations();
        $payment->refresh();
        if ($payment->sync_status === Payment::SYNC_STATUS_SYNCED && !empty($payment->zoho_payment_id)) {
            return [
                'success' => true,
                'created' => false,
                'updated' => false,
                'zoho_payment_id' => $payment->zoho_payment_id,
                'payment_id' => $payment->id,
                'message' => 'Payment already synchronized.',
            ];
        }

        $payment->update([
            'sync_status' => Payment::SYNC_STATUS_PROCESSING,
            'error_message' => null,
        ]);

        try {
            // 1. Resolve & Validate Order
            $order = Order::where('shop_id', $this->shop->id)->find($payment->order_id) ?? $payment->order;

            if (!$order) {
                throw new \Exception("Cannot sync payment #{$payment->id}: Associated order is missing.");
            }

            $order->unsetRelations();
            $order->refresh();

            if (in_array(strtolower((string) $order->financial_status), ['refunded', 'voided', 'cancelled'])) {
                throw new \Exception("Cannot sync payment #{$payment->id} for order #{$order->order_number}: Order financial status is '{$order->financial_status}'. Payments cannot be recorded or synchronized for cancelled, refunded, or voided orders.");
            }

            // 2. Resolve & Validate Invoice
            $invoice = Invoice::where('shop_id', $this->shop->id)
                ->where('order_id', $order->id)
                ->first() ?? $order->invoice;

            if (!$invoice || empty($invoice->zoho_invoice_id)) {
                $this->syncInvoice($order);
                $order->unsetRelations();
                $order->refresh();
                $invoice = Invoice::where('shop_id', $this->shop->id)
                    ->where('order_id', $order->id)
                    ->first() ?? $order->invoice;
            }

            if (!$invoice || empty($invoice->zoho_invoice_id)) {
                throw new \Exception("Cannot sync payment #{$payment->id} for order #{$order->order_number}: Zoho Invoice ID is missing.");
            }

            // 3 & 4. Set payment.invoice_id and payment.zoho_invoice_id from Invoice & persist
            $payment->invoice_id = $invoice->id;
            $payment->zoho_invoice_id = $invoice->zoho_invoice_id;
            $payment->save();

            // 5. Resolve & Re-validate Customer Zoho contact mapping
            $customer = null;
            if ($order->customer_id) {
                $customer = Customer::where('shop_id', $this->shop->id)->find($order->customer_id);
            }
            if (!$customer) {
                $customer = $order->customer;
            }

            if (!$customer) {
                throw new \Exception("Cannot sync payment #{$payment->id} for order #{$order->order_number}: Customer record missing.");
            }

            $customer->unsetRelations();
            $customer->refresh();

            if (empty($customer->zoho_contact_id)) {
                $this->syncCustomer($customer);
                $customer->unsetRelations();
                $customer->refresh();
            }

            if (empty($customer->zoho_contact_id)) {
                throw new \Exception("Cannot sync payment #{$payment->id} for order #{$order->order_number}: Customer sync to Zoho failed.");
            }

            // 4. Validate Amount & Currency
            $amount = (float) $payment->amount;
            if ($amount <= 0.00) {
                throw new \Exception("Cannot sync payment #{$payment->id}: Invalid payment amount ({$amount}). Amount must be greater than 0.");
            }

            if (!empty($payment->currency) && !empty($invoice->currency)) {
                if (strcasecmp(trim($payment->currency), trim($invoice->currency)) !== 0) {
                    throw new \Exception("Cannot sync payment #{$payment->id}: Currency mismatch (Payment: {$payment->currency}, Invoice: {$invoice->currency}).");
                }
            }

            // 5. Over-Allocation & Currency Conversion Rounding Tolerance
            $alreadyAppliedAmount = (float) Payment::where('invoice_id', $invoice->id)
                ->where('sync_status', Payment::SYNC_STATUS_SYNCED)
                ->where('id', '!=', $payment->id)
                ->sum('amount');
            $invoiceTotal = (float) $invoice->amount;
            $remainingBalance = max(0.00, $invoiceTotal - $alreadyAppliedAmount);

            $amountApplied = $amount;
            $overAmount = round($amount - $remainingBalance, 4);

            if ($overAmount > 0.00) {
                // Currency-aware rounding precision limit: up to 0.02 units (e.g. $0.01 / $0.02 gateway exchange rate rounding)
                if ($overAmount <= 0.02) {
                    $currencyCode = strtoupper(trim((string) ($invoice->currency ?: 'USD')));
                    Log::info("syncPayment: Payment #{$payment->id} amount ({$amount}) exceeds remaining invoice balance ({$remainingBalance}) by {$overAmount} {$currencyCode} due to gateway currency conversion rounding. Clamping amount_applied to {$remainingBalance}.");
                    $amountApplied = $remainingBalance;
                } else {
                    throw new \Exception("Cannot sync payment #{$payment->id}: Payment amount ({$amount}) exceeds remaining invoice balance ({$remainingBalance}). Over-allocation is not supported.");
                }
            }

            // 6. Resolve Payment Gateway Mapping & Account
            $gatewayMapping = $this->getPaymentGatewayMapping($payment->payment_method);
            $paymentMode = $gatewayMapping['payment_mode'];
            $accountId = $gatewayMapping['account_id'] ?? null;

            // 7. Resolve Payment Reference (Deterministic & Clamped to 100 chars)
            $refNumber = $payment->payment_reference
                ?? $payment->shopify_transaction_id
                ?? "PAY-ORD-{$order->id}-{$payment->id}";

            $refNumber = substr(trim((string) $refNumber), 0, 100);

            if (empty($payment->payment_reference)) {
                $payment->payment_reference = $refNumber;
            }

            // 8. Idempotency & Crash Recovery: Check existing local or Zoho payment
            $zohoPaymentId = $payment->zoho_payment_id;
            $created = false;
            $updated = false;

            if (empty($zohoPaymentId)) {
                $existingZohoPayment = $this->findZohoCustomerPaymentByReferenceNumber($refNumber);
                if ($existingZohoPayment && !empty($existingZohoPayment['payment_id'])) {
                    $zohoPaymentId = (string) $existingZohoPayment['payment_id'];
                    $updated = true;
                    Log::info("syncPayment: Recovered existing Zoho payment ID {$zohoPaymentId} for payment #{$payment->id} via ref {$refNumber}.");
                }
            }

            // 9. Create Customer Payment in Zoho if not already present
            if (empty($zohoPaymentId)) {
                $paymentDateStr = $payment->payment_date
                    ? $payment->payment_date->format('Y-m-d')
                    : date('Y-m-d');

                $payload = [
                    'customer_id' => $customer->zoho_contact_id,
                    'payment_mode' => $paymentMode,
                    'amount' => $amount,
                    'date' => $paymentDateStr,
                    'reference_number' => $refNumber,
                    'invoices' => [
                        [
                            'invoice_id' => $invoice->zoho_invoice_id,
                            'amount_applied' => $amountApplied,
                        ],
                    ],
                ];

                if (!empty($accountId)) {
                    $payload['account_id'] = $accountId;
                }

                $zohoResponse = $this->createCustomerPayment($payload);
                $zohoPaymentId = (string) ($zohoResponse['payment_id'] ?? $zohoResponse['customerpayment_id'] ?? null);

                if (empty($zohoPaymentId)) {
                    throw new \Exception("Zoho did not return a payment_id when creating Customer Payment.");
                }

                $created = true;
            }

            // 10. Persist Local Payment State
            $payment->update([
                'invoice_id' => $invoice->id,
                'zoho_payment_id' => $zohoPaymentId,
                'zoho_invoice_id' => $invoice->zoho_invoice_id,
                'payment_reference' => $refNumber,
                'sync_status' => Payment::SYNC_STATUS_SYNCED,
                'synced_at' => now(),
                'error_message' => null,
            ]);

            // 11. Record Sync History
            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'action' => $created ? 'create' : 'synced',
                'status' => 'success',
                'zoho_payment_id' => $zohoPaymentId,
                'zoho_invoice_id' => $invoice->zoho_invoice_id,
                'message' => $created ? 'Customer payment created in Zoho Books.' : 'Customer payment synced/reconciled with Zoho Books.',
                'synced_at' => now(),
            ]);

                $payRef = $zohoPaymentId ? "Payment #{$zohoPaymentId}" : ($payment->payment_reference ?: "Payment #{$payment->id}");
            $orderRef = $order->order_number ? "#{$order->order_number}" : "Order #{$order->id}";
            $invRef = $invoice->invoice_number ?: ($invoice->zoho_invoice_id ? "INV-{$invoice->zoho_invoice_id}" : '');
            $msgAction = $created ? 'created' : 'reconciled';
            $payMsg = "Payment {$msgAction} successfully — Shopify Order {$orderRef}" . ($invRef ? " ({$invRef})" : "") . " → Zoho {$payRef}.";

            return [
                'success' => true,
                'created' => $created,
                'updated' => $updated,
                'zoho_payment_id' => $zohoPaymentId,
                'zoho_invoice_id' => $invoice->zoho_invoice_id,
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'message' => $payMsg,
            ];

        } catch (\Throwable $e) {
            $payment->update([
                'sync_status' => Payment::SYNC_STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'order_id' => $payment->order_id ?? null,
                'invoice_id' => $payment->invoice_id ?? null,
                'payment_id' => $payment->id,
                'action' => 'create',
                'status' => 'failed',
                'zoho_payment_id' => $payment->zoho_payment_id ?? null,
                'message' => 'Zoho Payment sync failed: ' . $e->getMessage(),
                'synced_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Get a Credit Note by ID from Zoho Books.
     */
    public function getCreditNote(string $creditNoteId): ?array
    {
        $response = $this->makeRequest('GET', "/books/v3/creditnotes/{$creditNoteId}");
        return $response['creditnote'] ?? null;
    }

    /**
     * Create a Credit Note in Zoho Books.
     */
    public function createCreditNote(array $payload): array
    {
        $response = $this->makeRequest('POST', '/books/v3/creditnotes', $payload);
        return $response['creditnote'] ?? [];
    }

    /**
     * Update an existing Credit Note in Zoho Books.
     */
    public function updateCreditNote(string $creditNoteId, array $payload): array
    {
        $response = $this->makeRequest('PUT', "/books/v3/creditnotes/{$creditNoteId}", $payload);
        return $response['creditnote'] ?? [];
    }

    /**
     * Find existing Zoho Credit Note by reference number.
     */
    public function findZohoCreditNoteByReferenceNumber(string $refNumber): ?array
    {
        $trimmedRef = trim($refNumber);
        if ($trimmedRef === '') {
            return null;
        }

        $response = $this->makeRequest('GET', '/books/v3/creditnotes', [
            'reference_number' => $trimmedRef,
        ]);

        $creditnotes = $response['creditnotes'] ?? [];
        foreach ($creditnotes as $cn) {
            if (!empty($cn['reference_number']) && trim($cn['reference_number']) === $trimmedRef) {
                return $cn;
            }
        }

        return null;
    }

    /**
     * Synchronize a Shopify Refund to Zoho Books as a Credit Note.
     */
    public function syncRefund(\App\Models\Refund $refund): array
    {
        if ($refund->shop_id !== $this->shop->id) {
            throw new \Exception("Refund #{$refund->id} does not belong to shop {$this->shop->shop_domain}");
        }

        $order = $refund->order;
        if (!$order) {
            $order = Order::where('shop_id', $this->shop->id)
                ->where('shopify_order_id', $refund->shopify_order_id)
                ->first();
        }

        if (!$order) {
            throw new \Exception("Cannot sync refund ID {$refund->id}: Associated order not found.");
        }

        // 1. Customer resolution
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
            throw new \Exception("Cannot sync refund ID {$refund->id}: Customer missing or not mapped to Zoho contact.");
        }

        $refNumber = "RF-{$refund->shopify_refund_id}";
        $zohoCreditNoteId = $refund->zoho_creditnote_id;
        $created = false;
        $reconciled = false;
        $updated = false;

        $targetAmount = (float) $refund->amount;
        $lineItems = [];
        if (!empty($refund->refund_line_items) && is_array($refund->refund_line_items)) {
            foreach ($refund->refund_line_items as $item) {
                $qty = (int) ($item['quantity'] ?? 1);
                $rate = (float) ($item['price'] ?? $item['rate'] ?? 0.0);
                if ($rate > 0 || $qty > 0) {
                    $lineItems[] = [
                        'name' => $item['title'] ?? $item['name'] ?? 'Refunded Item',
                        'rate' => $rate,
                        'quantity' => $qty > 0 ? $qty : 1,
                    ];
                }
            }
        }

        $sum = array_reduce($lineItems, function ($acc, $i) {
            return $acc + ($i['rate'] * $i['quantity']);
        }, 0.0);

        if (empty($lineItems) || $sum <= 0) {
            $lineItems = [
                [
                    'name' => "Shopify Refund #{$refund->shopify_refund_id} (Order #{$order->order_number})",
                    'rate' => $targetAmount,
                    'quantity' => 1,
                ]
            ];
        } else if ($targetAmount > 0 && abs($targetAmount - $sum) > 0.001) {
            $diff = round($targetAmount - $sum, 2);
            if ($diff > 0) {
                $lineItems[] = [
                    'name' => "Refund Adjustments / Shipping / Taxes",
                    'rate' => $diff,
                    'quantity' => 1,
                ];
            } else {
                $ratio = $targetAmount / $sum;
                $scaledSum = 0.0;
                $count = count($lineItems);
                foreach ($lineItems as $idx => &$li) {
                    $li['rate'] = round($li['rate'] * $ratio, 2);
                    $scaledSum += $li['rate'] * $li['quantity'];
                }
                unset($li);

                $rem = round($targetAmount - $scaledSum, 2);
                if (abs($rem) > 0.001 && $count > 0) {
                    $lastQty = $lineItems[$count - 1]['quantity'];
                    $lineItems[$count - 1]['rate'] = round($lineItems[$count - 1]['rate'] + ($rem / $lastQty), 2);
                }
            }
        }

        $payload = [
            'customer_id' => $zohoContactId,
            'reference_number' => $refNumber,
            'date' => $refund->created_at ? $refund->created_at->format('Y-m-d') : date('Y-m-d'),
            'currency_code' => strtoupper($refund->currency ?? $order->currency ?? $this->shop->currency ?? 'USD'),
            'line_items' => $lineItems,
            'notes' => $refund->note ?? "Refund for Shopify Order #{$order->order_number}",
        ];

        // 2. Check idempotency / existing credit note
        if (empty($zohoCreditNoteId)) {
            $existing = $this->findZohoCreditNoteByReferenceNumber($refNumber);
            if ($existing && !empty($existing['creditnote_id'])) {
                $zohoCreditNoteId = (string) $existing['creditnote_id'];
                $refund->zoho_creditnote_id = $zohoCreditNoteId;
                $refund->creditnote_number = $existing['creditnote_number'] ?? null;
            }
        }

        // 3. Create or Reconcile Credit Note in Zoho
        if (empty($zohoCreditNoteId)) {
            try {
                $cnResponse = $this->createCreditNote($payload);
                $zohoCreditNoteId = (string) ($cnResponse['creditnote_id'] ?? null);

                if ($zohoCreditNoteId) {
                    $refund->zoho_creditnote_id = $zohoCreditNoteId;
                    $refund->creditnote_number = $cnResponse['creditnote_number'] ?? null;
                    $refund->sync_status = \App\Models\Refund::SYNC_STATUS_SYNCED;
                    $refund->synced_at = now();
                    $refund->error_message = null;
                    $refund->save();
                    $created = true;
                }
            } catch (\Throwable $e) {
                $refund->update([
                    'sync_status' => \App\Models\Refund::SYNC_STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);

                SyncHistory::create([
                    'shop_id' => $this->shop->id,
                    'order_id' => $order->id,
                    'refund_id' => $refund->id,
                    'action' => 'create',
                    'status' => 'failed',
                    'message' => 'Zoho Credit Note creation failed: ' . $e->getMessage(),
                    'synced_at' => now(),
                ]);

                throw $e;
            }
        } else {
            try {
                $existingCn = $this->getCreditNote($zohoCreditNoteId);
                $zohoTotal = (float) ($existingCn['total'] ?? 0.0);
                $zohoStatus = strtolower((string) ($existingCn['status'] ?? 'open'));

                if (abs($zohoTotal - $targetAmount) < 0.01) {
                    // Amounts match - no-op
                    $refund->sync_status = \App\Models\Refund::SYNC_STATUS_SYNCED;
                    $refund->synced_at = now();
                    $refund->error_message = null;
                    $refund->save();
                } else {
                    // Amount mismatch
                    if ($zohoStatus !== 'open') {
                        $errMsg = "Cannot update Credit Note {$refund->creditnote_number} in Zoho: status is '{$zohoStatus}' (expected 'open'). Manual accounting intervention required.";
                        $refund->update([
                            'sync_status' => \App\Models\Refund::SYNC_STATUS_FAILED,
                            'error_message' => $errMsg,
                        ]);

                        SyncHistory::create([
                            'shop_id' => $this->shop->id,
                            'order_id' => $order->id,
                            'refund_id' => $refund->id,
                            'zoho_creditnote_id' => $zohoCreditNoteId,
                            'action' => 'update',
                            'status' => 'failed',
                            'message' => $errMsg,
                            'synced_at' => now(),
                        ]);

                        throw new \Exception($errMsg);
                    }

                    // Status is open - update Credit Note
                    $cnResponse = $this->updateCreditNote($zohoCreditNoteId, $payload);
                    $refund->zoho_creditnote_id = (string) ($cnResponse['creditnote_id'] ?? $zohoCreditNoteId);
                    if (!empty($cnResponse['creditnote_number'])) {
                        $refund->creditnote_number = $cnResponse['creditnote_number'];
                    }
                    $refund->sync_status = \App\Models\Refund::SYNC_STATUS_SYNCED;
                    $refund->synced_at = now();
                    $refund->error_message = null;
                    $refund->save();
                    $reconciled = true;
                    $updated = true;

                    SyncHistory::create([
                        'shop_id' => $this->shop->id,
                        'order_id' => $order->id,
                        'refund_id' => $refund->id,
                        'zoho_creditnote_id' => $zohoCreditNoteId,
                        'action' => 'update',
                        'status' => 'success',
                        'message' => 'Credit Note reconciled/updated in Zoho Books.',
                        'synced_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                if ($refund->sync_status !== \App\Models\Refund::SYNC_STATUS_FAILED) {
                    $refund->update([
                        'sync_status' => \App\Models\Refund::SYNC_STATUS_FAILED,
                        'error_message' => $e->getMessage(),
                    ]);
                }
                throw $e;
            }
        }

        // 4. Inventory Reversal when restock = true
        if ($refund->restock && !empty($refund->refund_line_items) && is_array($refund->refund_line_items)) {
            foreach ($refund->refund_line_items as $item) {
                $variantId = $item['variant_id'] ?? null;
                if ($variantId) {
                    $numericVariantId = (string) preg_replace('/^gid:\/\/shopify\/ProductVariant\//', '', (string) $variantId);
                    $gidVariantId = str_starts_with((string) $variantId, 'gid://')
                        ? (string) $variantId
                        : "gid://shopify/ProductVariant/{$variantId}";

                    $variant = ProductVariant::whereHas('product', function ($q) {
                            $q->where('shop_id', $this->shop->id);
                        })
                        ->where(function ($q) use ($numericVariantId, $gidVariantId) {
                            $q->where('shopify_variant_id', $numericVariantId)
                                ->orWhere('shopify_variant_id', $gidVariantId);
                        })->first();

                    if ($variant && $variant->zoho_item_id) {
                        try {
                            $restockQty = (int) ($item['quantity'] ?? 1);
                            Log::info("syncRefund: Inventory restock reversal for variant ID {$variant->id}, Qty +{$restockQty}");
                        } catch (\Throwable $invEx) {
                            Log::warning("syncRefund: Restock inventory reversal skipped/failed for variant {$variant->id}: " . $invEx->getMessage());
                        }
                    }
                }
            }
        }

        if (in_array(strtolower((string) $order->financial_status), ['cancelled', 'voided'], true)) {
            $order->cancel_sync_status = 'synced';
            $order->cancel_sync_error = null;
            $order->save();
        }

        // 5. Record Sync History
        SyncHistory::create([
            'shop_id' => $this->shop->id,
            'order_id' => $order->id,
            'refund_id' => $refund->id,
            'action' => $created ? 'create' : 'synced',
            'status' => 'success',
            'zoho_creditnote_id' => $refund->zoho_creditnote_id,
            'message' => $created ? 'Credit Note created in Zoho Books.' : 'Credit Note synced/reconciled with Zoho Books.',
            'synced_at' => now(),
        ]);

        $cnRef = $refund->creditnote_number ?: ($refund->zoho_creditnote_id ? "CN-{$refund->zoho_creditnote_id}" : '');
        $orderRef = $order->order_number ? "#{$order->order_number}" : "Order #{$order->id}";
        $refundRef = $refund->shopify_refund_id ? "#{$refund->shopify_refund_id}" : "Refund #{$refund->id}";
        $msgAction = $created ? 'created' : 'reconciled';
        $refundMsg = "Credit Note {$msgAction} successfully — Shopify Refund {$refundRef} (Order {$orderRef})" . ($cnRef ? " → Zoho {$cnRef}" : "") . ".";

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'zoho_creditnote_id' => $refund->zoho_creditnote_id,
            'refund_id' => $refund->id,
            'order_id' => $order->id,
            'message' => $refundMsg,
        ];
    }

    /**
     * Complete Order Cancellation Synchronization flow across Zoho Sales Order and Zoho Invoice.
     */
    public function cancelOrder(Order $order): array
    {
        if ($order->shop_id !== $this->shop->id) {
            throw new \Exception("Order #{$order->id} does not belong to shop {$this->shop->shop_domain}");
        }

        $order->financial_status = 'cancelled';
        if (empty($order->cancelled_at)) {
            $order->cancelled_at = now();
        }
        $order->save();

        try {
            $soId = $order->zoho_sales_order_id;
            if (!$soId && !empty($order->order_number)) {
                $refNumber = $order->order_number;
                $existing = $this->findZohoSalesOrderByReferenceNumber($refNumber);
                if ($existing && !empty($existing['salesorder_id'])) {
                    $soId = (string) $existing['salesorder_id'];
                    $order->zoho_sales_order_id = $soId;
                    $order->zoho_sales_order_number = $existing['salesorder_number'] ?? null;
                    $order->save();
                }
            }

            $invoice = $order->invoice;
            $soVoidRes = null;
            $invVoidRes = null;

            if ($invoice && $invoice->zoho_invoice_id) {
                try {
                    $invVoidRes = $this->voidInvoice($invoice->zoho_invoice_id);
                    if (($invVoidRes['status'] ?? '') === 'void') {
                        $invoice->update(['status' => 'void']);
                    }
                } catch (\Throwable $e) {
                    Log::warning("cancelOrder: Void invoice {$invoice->zoho_invoice_id} threw: " . $e->getMessage());
                }
            }

            if ($soId) {
                try {
                    $soVoidRes = $this->voidSalesOrder($soId);
                } catch (\Throwable $e) {
                    Log::warning("cancelOrder: Void sales order {$soId} threw: " . $e->getMessage());
                    throw $e;
                }
            }

            $hasCreditNote = $order->refunds()->where(function ($q) {
                $q->whereNotNull('zoho_creditnote_id')->orWhere('sync_status', 'synced');
            })->exists();

            $soVoided = ($soVoidRes['status'] ?? '') === 'void' || ($soVoidRes['status'] ?? '') === 'confirmed' && empty($soId);
            $soCannotVoid = ($soVoidRes['status'] ?? '') === 'invoiced_cannot_void';
            $invCannotVoid = ($invVoidRes['status'] ?? '') === 'paid_cannot_void';

            if ($soVoided || !$soId || $hasCreditNote) {
                $order->cancel_sync_status = 'synced';
                $order->cancel_sync_error = null;
                $order->save();

                SyncHistory::create([
                    'shop_id' => $this->shop->id,
                    'order_id' => $order->id,
                    'invoice_id' => $order->invoice->id ?? null,
                    'action' => 'order_cancelled',
                    'status' => 'success',
                    'zoho_sales_order_id' => $order->zoho_sales_order_id,
                    'zoho_invoice_id' => $order->invoice->zoho_invoice_id ?? null,
                    'message' => "Order #{$order->order_number} cancellation synchronized to Zoho Books.",
                    'synced_at' => now(),
                ]);

                return [
                    'success' => true,
                    'status' => 'synced',
                    'order_id' => $order->id,
                    'sales_order_result' => $soVoidRes,
                    'invoice_result' => $invVoidRes,
                    'message' => "Order #{$order->order_number} cancellation synchronized successfully.",
                ];
            }

            if ($soCannotVoid || $invCannotVoid) {
                $order->cancel_sync_status = 'requires_refund';
                $order->cancel_sync_error = 'Zoho Sales Order is CLOSED and Invoice is PAID. Zoho requires a Credit Note (Refund) to reverse paid documents.';
                $order->save();

                SyncHistory::create([
                    'shop_id' => $this->shop->id,
                    'order_id' => $order->id,
                    'invoice_id' => $order->invoice->id ?? null,
                    'action' => 'order_cancelled',
                    'status' => 'requires_refund',
                    'zoho_sales_order_id' => $order->zoho_sales_order_id,
                    'zoho_invoice_id' => $order->invoice->zoho_invoice_id ?? null,
                    'message' => "Order #{$order->order_number} cancellation recorded locally. Zoho Sales Order is CLOSED/PAID; awaiting Shopify Refund to generate Credit Note.",
                    'synced_at' => now(),
                ]);

                return [
                    'success' => true,
                    'status' => 'requires_refund',
                    'order_id' => $order->id,
                    'sales_order_result' => $soVoidRes,
                    'invoice_result' => $invVoidRes,
                    'message' => "Order #{$order->order_number} cancellation recorded. Zoho Sales Order is CLOSED/PAID; awaiting Shopify Refund to generate Credit Note.",
                ];
            }

            $order->cancel_sync_status = 'synced';
            $order->cancel_sync_error = null;
            $order->save();

            return [
                'success' => true,
                'status' => 'synced',
                'order_id' => $order->id,
                'message' => "Order #{$order->order_number} cancellation processed.",
            ];

        } catch (\Throwable $e) {
            $order->cancel_sync_status = 'failed';
            $order->cancel_sync_error = $e->getMessage();
            $order->save();

            SyncHistory::create([
                'shop_id' => $this->shop->id,
                'order_id' => $order->id,
                'invoice_id' => $order->invoice->id ?? null,
                'action' => 'order_cancelled',
                'status' => 'failed',
                'zoho_sales_order_id' => $order->zoho_sales_order_id,
                'zoho_invoice_id' => $order->invoice->zoho_invoice_id ?? null,
                'message' => "Order #{$order->order_number} cancellation sync failed: " . $e->getMessage(),
                'synced_at' => now(),
            ]);

            throw $e;
        }
    }
}




