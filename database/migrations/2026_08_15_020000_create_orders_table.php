<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null');
            $table->string('shopify_order_id')->index();
            $table->string('order_number')->nullable()->index();
            $table->string('zoho_sales_order_id')->nullable()->index();
            $table->string('zoho_sales_order_number')->nullable();
            $table->timestamp('order_date')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->decimal('discount_total', 12, 2)->default(0.00);
            $table->decimal('shipping_total', 12, 2)->default(0.00);
            $table->decimal('tax_total', 12, 2)->default(0.00);
            $table->decimal('total_price', 12, 2)->default(0.00);
            $table->string('financial_status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->json('line_items')->nullable();
            $table->text('notes')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('zoho_sync_hash')->nullable();
            $table->timestamp('zoho_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_order_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('orders');
    }
};
