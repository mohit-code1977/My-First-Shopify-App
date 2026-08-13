<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration{
    // Add the Zoho Item ID column to products
    public function up(): void{
        if (!Schema::hasColumn('products', 'zoho_item_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('zoho_item_id')->nullable()->after('handle');
            });
        }
    }

    // Remove the Zoho Item ID column when rolling back
    public function down(): void{
        // Do not drop zoho_item_id here because 2026_08_10_110810_add_zoho_item_id_to_products_table owns the column
    }
};