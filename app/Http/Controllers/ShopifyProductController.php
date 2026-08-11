<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Services\ShopifyService;
use App\Services\ZohoService;
use Illuminate\Support\Facades\Http;

class ShopifyProductController extends Controller{
    // Inject the Shopify service when creating the controller
    public function __construct(
        private ShopifyService $shopifyService
    ) {}

    // Fetch products from Shopify and synchronize products, variants, and Zoho items
    public function products()
    {
        // Get the installed Shopify shop
        $shop = Shop::first();

        // Stop if no Shopify shop is installed
        if (!$shop) {
            return response()->json([
                'error' => 'No Shopify shop installed',
            ], 404);
        }

        // Create the Zoho service for the current Shopify shop
        $zohoService = new ZohoService($shop);

        // GraphQL query to fetch Shopify products and their variants
        $query = <<<'GRAPHQL'
query {
    products(first: 10) {
        nodes {
            id
            title
            handle
            variants(first: 10) {
                nodes {
                    id
                    title
                    sku
                    price
                    inventoryQuantity
                }
            }
        }
    }
}
GRAPHQL;

        // Get a valid Shopify access token
        $accessToken = $this->shopifyService->getValidAccessToken($shop);

        // Send the GraphQL request to Shopify
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->post(
            "https://{$shop->shop_domain}/admin/api/2026-07/graphql.json",
            [
                'query' => $query,
            ]
        );

        // Handle HTTP-level Shopify API errors
        if (!$response->successful()) {
            return response()->json([
                'error' => 'Shopify API request failed',
                'status' => $response->status(),
                'response' => $response->json(),
            ], 500);
        }

        // Decode the Shopify response
        $responseData = $response->json();

        // Handle Shopify GraphQL errors
        if (!empty($responseData['errors'])) {
            return response()->json([
                'error' => 'Shopify GraphQL request failed',
                'errors' => $responseData['errors'],
            ], 500);
        }

        // Extract the products from Shopify's GraphQL response
        $products = $responseData['data']['products']['nodes'] ?? [];

        $syncedProducts = 0;
        $syncedVariants = 0;
        $createdZohoItems = 0;
        $updatedZohoItems = 0;
        $zohoErrors = [];

        // Process every Shopify product
        foreach ($products as $shopifyProduct) {

            // Create or update the Shopify product in our database
            $product = Product::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_product_id' => $shopifyProduct['id'],
                ],
                [
                    'title' => $shopifyProduct['title'],
                    'handle' => $shopifyProduct['handle'],
                ]
            );

            $syncedProducts++;

            // Process every variant belonging to this product
            foreach ($shopifyProduct['variants']['nodes'] ?? [] as $shopifyVariant) {

                // Create or update the Shopify variant in our database
                $variant = ProductVariant::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'shopify_variant_id' => $shopifyVariant['id'],
                    ],
                    [
                        'title' => $shopifyVariant['title'],
                        'sku' => $shopifyVariant['sku'],
                        'price' => $shopifyVariant['price'],
                        'inventory_quantity' => $shopifyVariant['inventoryQuantity'],
                    ]
                );

                $syncedVariants++;

                // Synchronize the Shopify variant with Zoho Books
                try {
                    $zohoResult = $zohoService->syncItem($variant);

                    if ($zohoResult['created'] ?? false) {
                        $createdZohoItems++;
                    }

                    if ($zohoResult['updated'] ?? false) {
                        $updatedZohoItems++;
                    }
                } catch (\Throwable $e) {
                    // Keep Shopify synchronization successful even if Zoho fails
                    $zohoErrors[] = [
                        'variant_id' => $variant->id,
                        'shopify_variant_id' => $variant->shopify_variant_id,
                        'title' => $variant->title,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        // Return a synchronization summary
        return response()->json([
            'message' => 'Shopify products, variants, and Zoho items synchronized successfully.',
            'products_synced' => $syncedProducts,
            'variants_synced' => $syncedVariants,
            'zoho_items_created' => $createdZohoItems,
            'zoho_items_updated' => $updatedZohoItems,
            'zoho_errors' => $zohoErrors,
        ]);
    }
}
