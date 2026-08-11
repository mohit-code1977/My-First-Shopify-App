<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

class ShopifyService
{
    public function getValidAccessToken(Shop $shop): string
    {
        if (
            $shop->access_token_expires_at &&
            now()->lt($shop->access_token_expires_at)
        ) {
            return $shop->access_token;
        }

        return $this->refreshAccessToken($shop);
    }

    private function refreshAccessToken(Shop $shop): string
    {
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
                'Shopify token refresh failed: ' . $response->body()
            );
        }

        $data = $response->json();

        $shop->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'scope' => $data['scope'] ?? $shop->scope,
            'access_token_expires_at' => now()->addSeconds(
                $data['expires_in']
            ),
        ]);

        return $data['access_token'];
    }
}