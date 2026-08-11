<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model{
    protected $fillable = [
        'shop_domain',
        'access_token',
        'refresh_token',
        'scope',
        'access_token_expires_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_token_expires_at' => 'datetime',
    ];

    public function products(){
        return $this->hasMany(Product::class);
    }

    public function zohoConnection(){
    return $this->hasOne(ZohoConnection::class);
}
}