<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'shop_id',
        'customer_id',
        'shopify_order_id',
        'order_number',
        'zoho_sales_order_id',
        'zoho_sales_order_number',
        'order_date',
        'currency',
        'subtotal',
        'discount_total',
        'shipping_total',
        'shipping_method',
        'shipping_address',
        'shipping_lines',
        'tracking_number',
        'tracking_company',
        'tracking_url',
        'fulfillments',
        'tax_total',
        'total_price',
        'financial_status',
        'cancelled_at',
        'cancel_reason',
        'cancel_sync_status',
        'cancel_sync_error',
        'fulfillment_status',
        'line_items',
        'tax_lines',
        'taxes_included',
        'notes',
        'coupon_code',
        'zoho_sync_hash',
        'zoho_synced_at',
    ];

    protected $casts = [
        'line_items' => 'array',
        'tax_lines' => 'array',
        'shipping_address' => 'array',
        'shipping_lines' => 'array',
        'fulfillments' => 'array',
        'taxes_included' => 'boolean',
        'order_date' => 'datetime',
        'cancelled_at' => 'datetime',
        'zoho_synced_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    protected $appends = [
        'payment_status',
        'payment_sync_status',
        'zoho_payment_id',
    ];

    public function getPaymentStatusAttribute(): string
    {
        $status = strtolower($this->financial_status ?? 'pending');
        if (in_array($status, ['partially_refunded', 'refunded', 'paid', 'partially_paid', 'authorized'])) {
            return $status;
        }
        if (!empty($this->cancelled_at) || in_array($status, ['cancelled', 'voided'])) {
            return in_array($status, ['cancelled', 'voided']) ? $status : 'cancelled';
        }
        return $status;
    }

    public function getZohoPaymentIdAttribute(): ?string
    {
        if ($this->relationLoaded('payments')) {
            foreach ($this->payments as $p) {
                if (!empty($p->zoho_payment_id)) {
                    return (string) $p->zoho_payment_id;
                }
            }
        }
        return null;
    }

    public function getPaymentSyncStatusAttribute(): string
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : collect();

        $hasSynced = $payments->contains(function ($p) {
            return $p->sync_status === 'synced' || !empty($p->zoho_payment_id);
        });

        if ($hasSynced) {
            return 'synced';
        }

        $hasFailed = $payments->contains(function ($p) {
            return $p->sync_status === 'failed';
        });

        if ($hasFailed) {
            return 'failed';
        }

        $bizStatus = $this->payment_status;
        if (in_array($bizStatus, ['paid', 'partially_paid'])) {
            $shop = $this->relationLoaded('shop') ? $this->shop : Shop::find($this->shop_id);
            if ($shop && $shop->zohoConnection !== null) {
                return 'pending_sync';
            }
            return 'not_synced';
        }

        return 'not_synced';
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function syncHistories(): HasMany
    {
        return $this->hasMany(SyncHistory::class);
    }
}
