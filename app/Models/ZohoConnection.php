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
        'inventory_capability',
        'setup_status',
        'custom_field_mappings',
        'setup_summary',
        'preflight_run_at',
        'expires_at',
        'connected_at',
        'disconnected_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'custom_field_mappings' => 'array',
        'setup_summary' => 'array',
        'preflight_run_at' => 'datetime',
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

    public function getReadinessLabelAttribute(): string
    {
        if (!$this->is_active) {
            return 'Disconnected';
        }

        return match ($this->setup_status) {
            'ready' => 'Integration Ready',
            'setup_required' => 'Connected — Setup Required',
            default => 'Connected',
        };
    }
}