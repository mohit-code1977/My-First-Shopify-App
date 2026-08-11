<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration{
    public function up(): void{
    Schema::create('product_variants', function (Blueprint $table) {
        $table->id();

        $table->foreignId('product_id')
            ->constrained('products')
            ->cascadeOnDelete();

        $table->string('shopify_variant_id')->unique();
        $table->string('title');
        $table->string('sku')->nullable();
        $table->decimal('price', 12, 2)->nullable();
        $table->integer('inventory_quantity')->nullable();
        $table->string('zoho_item_id')->nullable();

        $table->timestamps();
    });
}

   
    public function down(): void{
    Schema::dropIfExists('product_variants');
}
};
