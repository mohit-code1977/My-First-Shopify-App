<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model {
    protected $fillable = [
        'product_id',
        'shopify_variant_id',
        'shopify_inventory_item_id',
        'title',
        'sku',
        'price',
        'inventory_quantity',
        'last_synced_quantity',
        'last_sync_source',
        'inventory_sync_version',
        'zoho_item_id',
        'zoho_sync_hash',
        'zoho_synced_at',
    ];

    protected $casts = [
        'inventory_quantity' => 'integer',
        'last_synced_quantity' => 'integer',
        'inventory_sync_version' => 'integer',
        'zoho_synced_at' => 'datetime',
    ];

    // Get the Shopify product that owns this variant
    public function product() {
        return $this->belongsTo(Product::class);
    }

    public function syncHistories() {
    return $this->hasMany(SyncHistory::class);
}
}