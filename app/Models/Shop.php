<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = [
        'shop_domain',
        'access_token',
        'refresh_token',
        'scope',
        'payment_gateway_settings',
        'access_token_expires_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'payment_gateway_settings' => 'array',
        'access_token_expires_at' => 'datetime',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function zohoConnection()
    {
        return $this->hasOne(ZohoConnection::class)->where('is_active', true);
    }

    public function allZohoConnections()
    {
        return $this->hasMany(ZohoConnection::class);
    }
}