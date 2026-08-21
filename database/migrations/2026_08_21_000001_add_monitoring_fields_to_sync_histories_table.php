<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->string('entity')->nullable()->after('shop_id');
            $table->string('trigger')->nullable()->after('action');
            $table->string('trigger_subtype')->nullable()->after('trigger');
            $table->string('shopify_id')->nullable()->after('status');
            $table->string('zoho_id')->nullable()->after('shopify_id');
            $table->string('error_code')->nullable()->after('zoho_id');
            $table->text('error_message')->nullable()->after('error_code');
            $table->integer('duration_ms')->nullable()->after('error_message');
            $table->json('metadata')->nullable()->after('duration_ms');
            $table->timestamp('started_at')->nullable()->after('metadata');
            $table->timestamp('completed_at')->nullable()->after('started_at');

            $table->index(['shop_id', 'entity']);
            $table->index(['shop_id', 'trigger']);
        });
    }

    public function down(): void {
        Schema::table('sync_histories', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'entity']);
            $table->dropIndex(['shop_id', 'trigger']);

            $table->dropColumn([
                'entity',
                'trigger',
                'trigger_subtype',
                'shopify_id',
                'zoho_id',
                'error_code',
                'error_message',
                'duration_ms',
                'metadata',
                'started_at',
                'completed_at',
            ]);
        });
    }
};
