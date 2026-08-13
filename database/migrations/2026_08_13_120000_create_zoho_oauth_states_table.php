<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zoho_oauth_states', function (Blueprint $table) {
            $table->id();

            $table->string('state', 128)->unique();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->string('host');

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_oauth_states');
    }
};
