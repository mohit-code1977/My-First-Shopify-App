<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoConnection extends Model
{
    protected $fillable = [
        'shop_id',
        'is_active',
        'organization_id',
        'organization_name',
        'access_token',
        'refresh_token',
        'accounts_url',
        'api_url',
        'api_domain',
        'data_center',
        'scope',
        'expires_at',
        'connected_at',
        'disconnected_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}