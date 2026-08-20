<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('last_synced_quantity')->nullable()->after('inventory_quantity');
            $table->string('last_sync_source')->nullable()->after('last_synced_quantity');
            $table->unsignedInteger('inventory_sync_version')->default(0)->after('last_sync_source');
        });
    }

    public function down(): void {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'last_synced_quantity',
                'last_sync_source',
                'inventory_sync_version',
            ]);
        });
    }
};
