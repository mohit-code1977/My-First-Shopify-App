<?php

namespace App\Services;

use App\Models\SyncHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncLogger
{
    /**
     * Centralized recording method for all sync history events.
     * Guarantees standardized formatting, exact entity context, timing, error capture, and JSON metadata.
     */
    public static function record(array $data): SyncHistory
    {
        $startedAt = $data['started_at'] ?? null;
        $completedAt = $data['completed_at'] ?? now();

        $durationMs = $data['duration_ms'] ?? null;
        if ($durationMs === null && $startedAt && $completedAt) {
            try {
                $start = is_string($startedAt) ? Carbon::parse($startedAt) : $startedAt;
                $end = is_string($completedAt) ? Carbon::parse($completedAt) : $completedAt;
                $durationMs = abs((int) round($end->diffInMilliseconds($start)));
            } catch (\Throwable $e) {
                $durationMs = null;
            }
        }

        // Standardize Entity
        $entity = strtolower(trim((string) ($data['entity'] ?? 'product')));
        if (in_array($entity, ['variant', 'product_variant', 'productvariant'])) {
            $entity = 'product_variant';
        } elseif (in_array($entity, ['creditnote', 'credit_note'])) {
            $entity = 'credit_note';
        }

        // Standardize Action
        $action = strtoupper(trim((string) ($data['action'] ?? 'SYNC')));

        // Standardize Trigger & Subtype
        $trigger = strtolower(trim((string) ($data['trigger'] ?? 'automatic')));
        $triggerSubtype = !empty($data['trigger_subtype']) ? strtolower(trim((string) $data['trigger_subtype'])) : null;
        if ($trigger === 'automatic' && empty($triggerSubtype)) {
            if ($entity === 'order') {
                $triggerSubtype = 'order_sync';
            } elseif ($entity === 'product' || $entity === 'product_variant') {
                $triggerSubtype = 'product_sync';
            } elseif ($entity === 'customer') {
                $triggerSubtype = 'customer_sync';
            } elseif ($entity === 'invoice') {
                $triggerSubtype = 'invoice_sync';
            } elseif ($entity === 'payment') {
                $triggerSubtype = 'payment_sync';
            } elseif ($entity === 'refund' || $entity === 'credit_note') {
                $triggerSubtype = 'refund_sync';
            } elseif ($entity === 'inventory') {
                $triggerSubtype = 'inventory_sync';
            }
        }

        // Standardize Status
        $status = strtoupper(trim((string) ($data['status'] ?? 'SUCCESS')));

        // Format metadata array ensuring no credentials or tokens are leaked
        $rawMetadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $sanitizedMetadata = self::sanitizeMetadata($rawMetadata);

        // Standardize Zoho & Shopify IDs
        $shopifyId = $data['shopify_id'] ?? ($sanitizedMetadata['shopify_id'] ?? null);
        $zohoId = $data['zoho_id'] ?? (
            $data['zoho_payment_id']
            ?? $data['zoho_invoice_id']
            ?? $data['zoho_item_id']
            ?? $data['zoho_creditnote_id']
            ?? ($sanitizedMetadata['zoho_id'] ?? null)
        );

        // Fallback message builder if message not explicitly provided
        $message = $data['message'] ?? null;
        if (empty($message)) {
            $triggerDisplay = $triggerSubtype ? "{$trigger} → {$triggerSubtype}" : $trigger;
            $message = strtoupper($entity) . " {$action} via {$triggerDisplay}: {$status}";
        }

        try {
            return SyncHistory::create([
                'shop_id' => $data['shop_id'],
                'entity' => $entity,
                'action' => $action,
                'trigger' => $trigger,
                'trigger_subtype' => $triggerSubtype,
                'status' => $status,
                'shopify_id' => $shopifyId ? (string) $shopifyId : null,
                'zoho_id' => $zohoId ? (string) $zohoId : null,
                'error_code' => $data['error_code'] ?? null,
                'error_message' => $data['error_message'] ?? null,
                'duration_ms' => $durationMs,
                'metadata' => $sanitizedMetadata,
                'message' => $message,
                'product_variant_id' => $data['product_variant_id'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'payment_id' => $data['payment_id'] ?? null,
                'refund_id' => $data['refund_id'] ?? null,
                'zoho_item_id' => $data['zoho_item_id'] ?? ($entity === 'product' || $entity === 'product_variant' ? $zohoId : null),
                'zoho_invoice_id' => $data['zoho_invoice_id'] ?? ($entity === 'invoice' ? $zohoId : null),
                'zoho_payment_id' => $data['zoho_payment_id'] ?? ($entity === 'payment' ? $zohoId : null),
                'zoho_creditnote_id' => $data['zoho_creditnote_id'] ?? ($entity === 'refund' || $entity === 'credit_note' ? $zohoId : null),
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'synced_at' => $completedAt ?? now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncLogger::record failed to persist history row: ' . $e->getMessage(), [
                'shop_id' => $data['shop_id'] ?? null,
                'entity' => $entity,
                'action' => $action,
            ]);

            // Re-throw or return unsaved fallback to avoid breaking calling code
            return new SyncHistory($data);
        }
    }

    /**
     * Remove any potential sensitive auth keys or tokens from metadata.
     */
    private static function sanitizeMetadata(array $metadata): array
    {
        $sensitiveKeys = ['access_token', 'refresh_token', 'client_secret', 'api_secret', 'authtoken', 'password'];

        foreach ($metadata as $key => $val) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                unset($metadata[$key]);
            } elseif (is_array($val)) {
                $metadata[$key] = self::sanitizeMetadata($val);
            }
        }

        return $metadata;
    }
}
