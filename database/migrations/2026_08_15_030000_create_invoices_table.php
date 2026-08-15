<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->string('shopify_order_id')->index();
            $table->string('zoho_invoice_id')->nullable()->index();
            $table->string('invoice_number')->nullable();
            $table->string('status')->default('created');
            $table->timestamp('invoice_date')->nullable();
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('currency', 3)->default('USD');
            $table->string('sync_status')->default('synced');
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'order_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('invoices');
    }
};
