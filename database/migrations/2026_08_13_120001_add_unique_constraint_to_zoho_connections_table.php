<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $duplicates = DB::table('zoho_connections')
            ->select('shop_id', DB::raw('COUNT(*) as count'))
            ->groupBy('shop_id')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot apply unique constraint: duplicate shop_id records detected in zoho_connections table.'
            );
        }

        Schema::table('zoho_connections', function (Blueprint $table) {
            $table->unique('shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_connections', function (Blueprint $table) {
            $table->dropUnique(['shop_id']);
        });
    }
};
