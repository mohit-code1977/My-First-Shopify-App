<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_method')->nullable()->after('shipping_total');
            $table->json('shipping_address')->nullable()->after('shipping_method');
            $table->json('shipping_lines')->nullable()->after('shipping_address');
            $table->string('tracking_number')->nullable()->after('shipping_lines');
            $table->string('tracking_company')->nullable()->after('tracking_number');
            $table->string('tracking_url')->nullable()->after('tracking_company');
            $table->json('fulfillments')->nullable()->after('tracking_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_method',
                'shipping_address',
                'shipping_lines',
                'tracking_number',
                'tracking_company',
                'tracking_url',
                'fulfillments',
            ]);
        });
    }
};
