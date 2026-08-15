<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'shop_id',
        'shopify_customer_id',
        'zoho_contact_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'billing_address',
        'shipping_address',
        'zoho_sync_hash',
        'zoho_synced_at',
    ];

    protected $casts = [
        'billing_address' => 'array',
        'shipping_address' => 'array',
        'zoho_synced_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
        return $name !== '' ? $name : ($this->email ?? 'Shopify Customer');
    }
}
