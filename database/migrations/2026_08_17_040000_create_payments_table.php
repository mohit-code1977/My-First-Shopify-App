<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();

            $table->string('shopify_order_id')->index();
            $table->string('shopify_payment_id')->nullable()->index();
            $table->string('zoho_payment_id')->nullable()->index();
            $table->string('zoho_invoice_id')->nullable()->index();

            $table->decimal('amount', 12, 2)->default(0.00);
            $table->string('currency', 10)->default('USD');
            $table->timestamp('payment_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status')->default('completed');
            $table->string('sync_status')->default('pending');
            $table->text('error_message')->nullable();
            $table->json('gateway_data')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'shopify_payment_id']);
            $table->unique(['shop_id', 'zoho_payment_id']);
            $table->index(['shop_id', 'sync_status']);
            $table->index(['shop_id', 'order_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('payments');
    }
};
