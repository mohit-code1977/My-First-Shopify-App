<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingInventoryWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'shopify_inventory_item_id',
        'webhook_id',
        'available_quantity',
        'status',
        'payload',
    ];

    protected $casts = [
        'available_quantity' => 'integer',
        'payload' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
