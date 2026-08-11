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
        Schema::create('shops', function (Blueprint $table) {
            $table->id();

            $table->string('shop_domain')->unique();

            $table->text('access_token');

            $table->text('refresh_token')->nullable();

            $table->string('scope')->nullable();

            $table->timestamp('access_token_expires_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
