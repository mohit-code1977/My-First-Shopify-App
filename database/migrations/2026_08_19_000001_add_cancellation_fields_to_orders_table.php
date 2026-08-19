<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('financial_status');
            }
            if (!Schema::hasColumn('orders', 'cancel_reason')) {
                $table->string('cancel_reason')->nullable()->after('cancelled_at');
            }
            if (!Schema::hasColumn('orders', 'cancel_sync_status')) {
                $table->string('cancel_sync_status')->nullable()->after('cancel_reason')->index();
            }
            if (!Schema::hasColumn('orders', 'cancel_sync_error')) {
                $table->text('cancel_sync_error')->nullable()->after('cancel_sync_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'cancel_sync_status']);
        });
    }
};
