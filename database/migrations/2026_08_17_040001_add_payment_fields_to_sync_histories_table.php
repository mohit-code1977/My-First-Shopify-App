<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->foreignId('payment_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('payments')
                ->nullOnDelete();

            $table->string('zoho_payment_id')
                ->nullable()
                ->after('zoho_invoice_id');
        });
    }

    public function down(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn(['payment_id', 'zoho_payment_id']);
        });
    }
};
