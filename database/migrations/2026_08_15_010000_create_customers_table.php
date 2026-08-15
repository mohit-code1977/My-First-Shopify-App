<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->onDelete('cascade');
            $table->string('shopify_customer_id')->index();
            $table->string('zoho_contact_id')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->string('zoho_sync_hash')->nullable();
            $table->timestamp('zoho_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
