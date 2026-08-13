<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('zoho_connections', 'accounts_url')) {
                $table->string('accounts_url')->nullable()->after('refresh_token');
            }
            if (!Schema::hasColumn('zoho_connections', 'api_url')) {
                $table->string('api_url')->nullable()->after('accounts_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('zoho_connections', 'api_url')) {
                $columnsToDrop[] = 'api_url';
            }
            if (Schema::hasColumn('zoho_connections', 'accounts_url')) {
                $columnsToDrop[] = 'accounts_url';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
