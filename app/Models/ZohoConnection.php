<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoConnection extends Model{
    protected $fillable = [
        'shop_id',
        'organization_id',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    public function shop(){
        return $this->belongsTo(Shop::class);
    }
}