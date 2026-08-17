<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model {
    // Business / Payment status constants (Shopify / Gateway state)
    public const STATUS_PAID = 'paid';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    // Synchronization status constants (Zoho sync state)
    public const SYNC_STATUS_PENDING = 'pending';
    public const SYNC_STATUS_PROCESSING = 'processing';
    public const SYNC_STATUS_SYNCED = 'synced';
    public const SYNC_STATUS_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'order_id',
        'invoice_id',
        'shopify_order_id',
        'shopify_payment_id',
        'shopify_transaction_id',
        'payment_reference',
        'zoho_payment_id',
        'zoho_invoice_id',
        'amount',
        'currency',
        'payment_date',
        'payment_method',
        'status',
        'sync_status',
        'error_message',
        'gateway_data',
        'synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'synced_at' => 'datetime',
        'gateway_data' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function syncHistories(): HasMany
    {
        return $this->hasMany(SyncHistory::class);
    }
}
