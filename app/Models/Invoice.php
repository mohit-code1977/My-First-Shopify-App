<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model {
    protected $fillable = [
        'shop_id',
        'order_id',
        'shopify_order_id',
        'zoho_invoice_id',
        'invoice_number',
        'status',
        'invoice_date',
        'amount',
        'currency',
        'sync_status',
        'synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_date' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function shop() {
        return $this->belongsTo(Shop::class);
    }

    public function order() {
        return $this->belongsTo(Order::class);
    }

    public function payments() {
        return $this->hasMany(Payment::class);
    }
}
