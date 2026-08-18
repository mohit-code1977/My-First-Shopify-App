<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->string('shopify_refund_id')->index();
            $table->string('shopify_order_id')->index();
            $table->string('zoho_creditnote_id')->nullable()->index();
            $table->string('creditnote_number')->nullable();

            $table->decimal('amount', 12, 2)->default(0.00);
            $table->string('currency', 10)->default('USD');
            $table->text('note')->nullable();
            $table->boolean('restock')->default(false);
            $table->json('refund_line_items')->nullable();

            $table->string('status')->default('completed');
            $table->string('sync_status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'shopify_refund_id']);
            $table->index(['shop_id', 'sync_status']);
            $table->index(['shop_id', 'order_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('refunds');
    }
};
