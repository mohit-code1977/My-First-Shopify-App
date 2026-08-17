<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('shopify_transaction_id')
                ->nullable()
                ->after('shopify_order_id')
                ->index();

            $table->string('payment_reference')
                ->nullable()
                ->after('shopify_transaction_id')
                ->index();

            $table->unique(['shop_id', 'shopify_transaction_id']);
            $table->unique(['shop_id', 'payment_reference']);
        });
    }

    public function down(): void {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'shopify_transaction_id']);
            $table->dropUnique(['shop_id', 'payment_reference']);
            $table->dropColumn(['shopify_transaction_id', 'payment_reference']);
        });
    }
};
