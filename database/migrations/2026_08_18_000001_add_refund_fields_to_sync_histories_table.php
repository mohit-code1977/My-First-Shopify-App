<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->foreignId('refund_id')
                ->nullable()
                ->after('payment_id')
                ->constrained('refunds')
                ->nullOnDelete();

            $table->string('zoho_creditnote_id')->nullable()->after('zoho_payment_id');
        });
    }

    public function down(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->dropForeign(['refund_id']);
            $table->dropColumn(['refund_id', 'zoho_creditnote_id']);
        });
    }
};
