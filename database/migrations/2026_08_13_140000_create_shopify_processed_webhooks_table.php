<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration { 
    public function up(): void {
        Schema::create('shopify_processed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('topic')->nullable();
            $table->string('shop_domain')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('shopify_processed_webhooks');
    }
};
