<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration{
    public function up(): void{
        Schema::create('zoho_connections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();

            $table->string('organization_id');

            $table->text('access_token');

            $table->text('refresh_token');

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void{
        Schema::dropIfExists('zoho_connections');
    }
};