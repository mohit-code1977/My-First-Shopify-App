<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $table->string('inventory_capability')->default('unknown')->after('scope');
        });
    }

    public function down(): void {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $table->dropColumn('inventory_capability');
        });
    }
};
