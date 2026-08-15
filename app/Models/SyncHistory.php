<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncHistory extends Model {
    protected $fillable = [
        'shop_id',
        'product_variant_id',
        'order_id',
        'invoice_id',
        'action',
        'status',
        'zoho_item_id',
        'zoho_invoice_id',
        'message',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function shop() {
        return $this->belongsTo(Shop::class);
    }

    public function productVariant() {
        return $this->belongsTo(ProductVariant::class);
    }

    public function order() {
        return $this->belongsTo(Order::class);
    }

    public function invoice() {
        return $this->belongsTo(Invoice::class);
    }
}