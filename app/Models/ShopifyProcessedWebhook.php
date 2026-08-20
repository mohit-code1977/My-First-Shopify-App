<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyProcessedWebhook extends Model {
    protected $fillable = [
        'webhook_id',
        'topic',
        'shop_domain',
        'status',
    ];

}
