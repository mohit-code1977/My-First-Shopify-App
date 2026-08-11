<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration{
    // Add the Zoho Item ID column to products
    public function up(): void{
        Schema::table('products', function (Blueprint $table) {
            $table->string('zoho_item_id')->nullable()->after('handle');
        });
    }

    // Remove the Zoho Item ID column when rolling back
   public function down(): void{
    Schema::table('products', function (Blueprint $table) {
        if (Schema::hasColumn('products', 'zoho_item_id')) {
            $table->dropColumn('zoho_item_id');
        }
    });
}
};