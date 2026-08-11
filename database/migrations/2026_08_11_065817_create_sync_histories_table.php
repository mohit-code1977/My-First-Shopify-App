<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('sync_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->string('action');
            $table->string('status');

            $table->string('zoho_item_id')->nullable();

            $table->text('message')->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'action']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('sync_histories');
    }
};
