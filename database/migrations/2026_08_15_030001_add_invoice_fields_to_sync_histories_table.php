<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->foreignId('order_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('orders')
                ->nullOnDelete();

            $table->foreignId('invoice_id')
                ->nullable()
                ->after('order_id')
                ->constrained('invoices')
                ->nullOnDelete();

            $table->string('zoho_invoice_id')
                ->nullable()
                ->after('zoho_item_id');
        });
    }

    public function down(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropColumn(['order_id', 'invoice_id', 'zoho_invoice_id']);
        });
    }
};
