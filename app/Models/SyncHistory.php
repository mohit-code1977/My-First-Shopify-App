<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncHistory extends Model {
    protected $fillable = [
        'shop_id',
        'product_variant_id',
        'order_id',
        'invoice_id',
        'payment_id',
        'refund_id',
        'action',
        'status',
        'zoho_item_id',
        'zoho_invoice_id',
        'zoho_payment_id',
        'zoho_creditnote_id',
        'message',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    protected $appends = [
        'entity_type',
        'entity_id',
        'details',
    ];

    /**
     * Virtual accessor for entity_type.
     */
    public function getEntityTypeAttribute(): string
    {
        if ($this->refund_id || !empty($this->zoho_creditnote_id)) {
            return 'refund';
        }
        if ($this->payment_id || !empty($this->zoho_payment_id)) {
            return 'payment';
        }
        if ($this->invoice_id || !empty($this->zoho_invoice_id)) {
            return 'invoice';
        }
        if ($this->order_id) {
            return 'order';
        }
        if ($this->product_variant_id || !empty($this->zoho_item_id)) {
            return 'product';
        }

        $action = strtolower((string) $this->action);
        if (str_contains($action, 'product') || str_contains($action, 'item') || str_contains($action, 'variant')) {
            return 'product';
        }
        if (str_contains($action, 'order')) {
            return 'order';
        }
        if (str_contains($action, 'invoice')) {
            return 'invoice';
        }
        if (str_contains($action, 'payment')) {
            return 'payment';
        }
        if (str_contains($action, 'refund') || str_contains($action, 'creditnote')) {
            return 'refund';
        }

        return 'system';
    }

    /**
     * Virtual accessor for entity_id.
     */
    public function getEntityIdAttribute(): ?string
    {
        if ($this->relationLoaded('productVariant') && $this->productVariant) {
            return $this->productVariant->sku ?: (string) $this->productVariant->id;
        }
        if ($this->relationLoaded('order') && $this->order) {
            return $this->order->order_number ? "#{$this->order->order_number}" : (string) $this->order->id;
        }
        if ($this->relationLoaded('invoice') && $this->invoice) {
            return $this->invoice->invoice_number ?: (string) $this->invoice->id;
        }
        if ($this->relationLoaded('payment') && $this->payment) {
            return $this->payment->payment_reference ?: (string) $this->payment->id;
        }
        if ($this->relationLoaded('refund') && $this->refund) {
            return (string) $this->refund->id;
        }

        return (string) (
            $this->product_variant_id
            ?? $this->order_id
            ?? $this->invoice_id
            ?? $this->payment_id
            ?? $this->refund_id
            ?? $this->zoho_payment_id
            ?? $this->zoho_invoice_id
            ?? $this->zoho_item_id
            ?? $this->id
        );
    }

    /**
     * Virtual accessor for details.
     */
    public function getDetailsAttribute(): ?string
    {
        return $this->message;
    }

    public function shop() {
        return $this->belongsTo(Shop::class);
    }

    public function productVariant() {
        return $this->belongsTo(ProductVariant::class);
    }

    public function order() {
        return $this->belongsTo(Order::class);
    }

    public function invoice() {
        return $this->belongsTo(Invoice::class);
    }

    public function payment() {
        return $this->belongsTo(Payment::class);
    }

    public function refund() {
        return $this->belongsTo(Refund::class);
    }
}