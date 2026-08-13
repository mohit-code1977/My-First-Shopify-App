<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('zoho_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('zoho_connections', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('shop_id');
            }
            if (!Schema::hasColumn('zoho_connections', 'organization_name')) {
                $table->string('organization_name')->nullable()->after('organization_id');
            }
            if (!Schema::hasColumn('zoho_connections', 'api_domain')) {
                $table->string('api_domain')->nullable()->after('api_url');
            }
            if (!Schema::hasColumn('zoho_connections', 'data_center')) {
                $table->string('data_center')->nullable()->after('api_domain');
            }
            if (!Schema::hasColumn('zoho_connections', 'scope')) {
                $table->string('scope')->nullable()->after('data_center');
            }
            if (!Schema::hasColumn('zoho_connections', 'connected_at')) {
                $table->timestamp('connected_at')->nullable()->after('expires_at');
            }
            if (!Schema::hasColumn('zoho_connections', 'disconnected_at')) {
                $table->timestamp('disconnected_at')->nullable()->after('connected_at');
            }

            $table->text('access_token')->nullable()->change();
            $table->text('refresh_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['is_active', 'organization_name', 'api_domain', 'data_center', 'scope', 'connected_at', 'disconnected_at'] as $col) {
                if (Schema::hasColumn('zoho_connections', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
