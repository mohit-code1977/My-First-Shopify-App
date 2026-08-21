<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model{
    protected $fillable = [
        'shop_id',
        'shopify_product_id',
        'title',
        'handle',
        'description',
        'image_url',
    ];

    // Get the Shopify shop that owns this product
    public function shop(){
        return $this->belongsTo(Shop::class);
    }

    // Get all variants belonging to this product
    public function variants(){
        return $this->hasMany(ProductVariant::class);
    }
}