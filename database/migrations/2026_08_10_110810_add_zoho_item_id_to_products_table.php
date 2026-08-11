<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Add Zoho Item ID to products table
    public function up(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->string('zoho_item_id')->nullable()->after('handle');
        });
    }

    // Remove Zoho Item ID if migration is rolled back
    public function down(): void {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('zoho_item_id');
        });
    }
};
