<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('shop_domain');
            }
        });
    }

    public function down(): void {
        Schema::table('shops', function (Blueprint $table) {
            if (Schema::hasColumn('shops', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
