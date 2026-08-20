<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pending_inventory_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->string('shopify_inventory_item_id')->index();
            $table->string('webhook_id')->nullable()->index();
            $table->integer('available_quantity')->default(0);
            $table->string('status')->default('pending')->index(); // pending, processed, skipped, failed
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_inventory_webhooks');
    }
};
