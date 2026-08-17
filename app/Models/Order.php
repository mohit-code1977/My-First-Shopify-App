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
        'tax_total',
        'total_price',
        'financial_status',
        'fulfillment_status',
        'line_items',
        'notes',
        'coupon_code',
        'zoho_sync_hash',
        'zoho_synced_at',
    ];

    protected $casts = [
        'line_items' => 'array',
        'order_date' => 'datetime',
        'zoho_synced_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

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
}
