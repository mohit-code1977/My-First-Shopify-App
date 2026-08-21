<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('zoho_connections', 'setup_status')) {
                $table->string('setup_status')->default('connected')->after('scope');
            }
            if (!Schema::hasColumn('zoho_connections', 'custom_field_mappings')) {
                $table->json('custom_field_mappings')->nullable()->after('setup_status');
            }
            if (!Schema::hasColumn('zoho_connections', 'setup_summary')) {
                $table->json('setup_summary')->nullable()->after('custom_field_mappings');
            }
            if (!Schema::hasColumn('zoho_connections', 'preflight_run_at')) {
                $table->timestamp('preflight_run_at')->nullable()->after('setup_summary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $cols = ['setup_status', 'custom_field_mappings', 'setup_summary', 'preflight_run_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('zoho_connections', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
