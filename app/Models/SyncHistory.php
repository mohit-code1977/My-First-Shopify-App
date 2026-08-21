<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncHistory extends Model
{
    protected $fillable = [
        'shop_id',
        'product_variant_id',
        'order_id',
        'invoice_id',
        'payment_id',
        'refund_id',
        'entity',
        'action',
        'trigger',
        'trigger_subtype',
        'status',
        'shopify_id',
        'zoho_id',
        'zoho_item_id',
        'zoho_invoice_id',
        'zoho_payment_id',
        'zoho_creditnote_id',
        'error_code',
        'error_message',
        'duration_ms',
        'metadata',
        'message',
        'started_at',
        'completed_at',
        'synced_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    protected $appends = [
        'entity_type',
        'entity_id',
        'details',
        'trigger_label',
        'formatted_duration',
    ];

    /**
     * Virtual accessor for entity_type (backward compatibility + normalized entity).
     */
    public function getEntityTypeAttribute(): string
    {
        if (!empty($this->entity)) {
            return strtolower($this->entity);
        }

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
     * Accessor for formatted trigger display (e.g. "Automatic → Order Sync" or "Manual").
     */
    public function getTriggerLabelAttribute(): string
    {
        $trig = ucfirst(strtolower((string) ($this->trigger ?? 'automatic')));

        if (!empty($this->trigger_subtype)) {
            $sub = str_replace('_', ' ', strtolower((string) $this->trigger_subtype));
            $subWords = ucwords($sub);
            return "{$trig} → {$subWords}";
        }

        return $trig;
    }

    /**
     * Accessor for formatted duration (e.g. "342 ms" or "1.45 s").
     */
    public function getFormattedDurationAttribute(): string
    {
        if ($this->duration_ms === null || $this->duration_ms === 0) {
            return '—';
        }

        if ($this->duration_ms < 1000) {
            return "{$this->duration_ms} ms";
        }

        $seconds = round($this->duration_ms / 1000, 2);
        return "{$seconds} s";
    }

    /**
     * Virtual accessor for entity_id.
     */
    public function getEntityIdAttribute(): ?string
    {
        if (!empty($this->shopify_id)) {
            return $this->shopify_id;
        }

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
        return $this->error_message ?: $this->message;
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }
}