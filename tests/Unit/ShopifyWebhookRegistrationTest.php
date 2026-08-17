<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Shop;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class ShopifyWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private ShopifyService $shopifyService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.api_secret' => 'test_api_secret']);
        env('SHOPIFY_APP_URL', 'https://test-app.ngrok-free.dev');

        $this->shop = Shop::create([
            'shop_domain' => 'registration-test-shop.myshopify.com',
            'access_token' => 'shpat_test_token_123',
        ]);

        $this->shopifyService = new ShopifyService();
    }

    public function test_register_all_webhooks_creates_subscriptions(): void
    {
        Http::fake([
            'https://registration-test-shop.myshopify.com/admin/api/2026-07/graphql.json' => function ($request) {
                $body = $request->body();
                if (str_contains($body, 'query {')) {
                    return Http::response([
                        'data' => [
                            'webhookSubscriptions' => [
                                'nodes' => [],
                            ],
                        ],
                    ], 200);
                }

                if (str_contains($body, 'webhookSubscriptionCreate')) {
                    return Http::response([
                        'data' => [
                            'webhookSubscriptionCreate' => [
                                'webhookSubscription' => [
                                    'id' => 'gid://shopify/WebhookSubscription/999',
                                    'topic' => 'ORDERS_CREATE',
                                    'uri' => 'https://test-app.ngrok-free.dev/webhooks/orders',
                                ],
                                'userErrors' => [],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([], 400);
            },
        ]);

        $result = $this->shopifyService->registerAllWebhooks($this->shop);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['order_create']['success']);
        $this->assertTrue($result['order_create']['created']);
        $this->assertEquals('gid://shopify/WebhookSubscription/999', $result['order_create']['webhook_id']);
    }

    public function test_register_webhook_updates_existing_subscription_when_url_changes(): void
    {
        Http::fake([
            'https://registration-test-shop.myshopify.com/admin/api/2026-07/graphql.json' => function ($request) {
                $body = $request->body();

                if (str_contains($body, 'query {')) {
                    return Http::response([
                        'data' => [
                            'webhookSubscriptions' => [
                                'nodes' => [
                                    [
                                        'id' => 'gid://shopify/WebhookSubscription/101',
                                        'topic' => 'ORDERS_CREATE',
                                        'uri' => 'https://old-tunnel.ngrok-free.dev/webhooks/orders',
                                    ],
                                ],
                            ],
                        ],
                    ], 200);
                }

                if (str_contains($body, 'webhookSubscriptionUpdate')) {
                    return Http::response([
                        'data' => [
                            'webhookSubscriptionUpdate' => [
                                'webhookSubscription' => [
                                    'id' => 'gid://shopify/WebhookSubscription/101',
                                    'topic' => 'ORDERS_CREATE',
                                    'uri' => 'https://test-app.ngrok-free.dev/webhooks/orders',
                                ],
                                'userErrors' => [],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([], 400);
            },
        ]);

        $result = $this->shopifyService->registerOrderCreateWebhook($this->shop);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['created']);
        $this->assertTrue($result['updated']);
        $this->assertEquals('gid://shopify/WebhookSubscription/101', $result['webhook_id']);
    }
}
