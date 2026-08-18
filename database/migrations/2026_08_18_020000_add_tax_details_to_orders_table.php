<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'tax_lines')) {
                $table->json('tax_lines')->nullable()->after('line_items');
            }
            if (!Schema::hasColumn('orders', 'taxes_included')) {
                $table->boolean('taxes_included')->default(false)->after('tax_lines');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'tax_lines')) {
                $table->dropColumn('tax_lines');
            }
            if (Schema::hasColumn('orders', 'taxes_included')) {
                $table->dropColumn('taxes_included');
            }
        });
    }
};
