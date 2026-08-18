<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Shop;

class ShopifyOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopify_scopes_configuration_includes_read_locations()
    {
        $scopes = config('services.shopify.scopes');

        $this->assertNotNull($scopes);
        $this->assertStringContainsString('read_locations', $scopes);
        $this->assertStringContainsString('write_customers', $scopes);
        $this->assertStringContainsString('write_inventory', $scopes);
        $this->assertStringContainsString('write_orders', $scopes);
        $this->assertStringContainsString('write_products', $scopes);
    }

    public function test_shopify_install_redirects_with_read_locations_scope()
    {
        config([
            'services.shopify.api_key' => 'test_api_key',
            'services.shopify.scopes' => 'read_products,write_products,read_orders,write_orders,read_customers,write_customers,read_inventory,write_inventory,read_locations',
        ]);

        $response = $this->get('/auth/install?shop=test-store.myshopify.com');

        $response->assertStatus(302);
        $targetUrl = $response->headers->get('Location');
        
        $this->assertStringContainsString('https://test-store.myshopify.com/admin/oauth/authorize', $targetUrl);
        $this->assertStringContainsString('read_locations', $targetUrl);
        $this->assertStringContainsString('write_inventory', $targetUrl);
    }
}
