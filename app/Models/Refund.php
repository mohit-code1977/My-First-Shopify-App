<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model {
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    public const SYNC_STATUS_PENDING = 'pending';
    public const SYNC_STATUS_SYNCED = 'synced';
    public const SYNC_STATUS_FAILED = 'failed';
    public const SYNC_STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'shop_id',
        'order_id',
        'shopify_refund_id',
        'shopify_order_id',
        'zoho_creditnote_id',
        'creditnote_number',
        'amount',
        'currency',
        'note',
        'restock',
        'refund_line_items',
        'status',
        'sync_status',
        'error_message',
        'synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'restock' => 'boolean',
        'refund_line_items' => 'array',
        'synced_at' => 'datetime',
    ];

    public function shop(): BelongsTo {
        return $this->belongsTo(Shop::class);
    }

    public function order(): BelongsTo {
        return $this->belongsTo(Order::class);
    }

    public function syncHistories(): HasMany {
        return $this->hasMany(SyncHistory::class);
    }
}
