<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Add fields used to track the last successful Zoho synchronization
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('zoho_sync_hash')->nullable()->after('zoho_item_id');
            $table->timestamp('zoho_synced_at')->nullable()->after('zoho_sync_hash');
        });
    }

    // Remove Zoho synchronization tracking fields
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'zoho_sync_hash',
                'zoho_synced_at',
            ]);
        });
    }
};