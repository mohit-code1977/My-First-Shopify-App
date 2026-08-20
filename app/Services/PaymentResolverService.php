<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Automatic payment resolution service.
 *
 * Replaces the obsolete manual payment gateway configuration system.
 * Deterministically maps Shopify payment gateways to Zoho Books payment modes
 * without requiring user configuration.
 */
class PaymentResolverService
{
    /**
     * Zoho Books documented payment_mode values:
     * 'creditcard', 'paypal', 'cash', 'banktransfer', 'check', 'bankremittance', 'autotransaction', 'others'
     */

    /**
     * Deterministic gateway normalization map.
     * Maps Shopify payment gateway identifiers (lowercased) to Zoho payment modes.
     */
    private const GATEWAY_MAP = [
        // Card-based gateways
        'shopify_payments'  => 'creditcard',
        'stripe'            => 'creditcard',
        'credit_card'       => 'creditcard',
        'bogus'             => 'creditcard',
        'bogus_gateway'     => 'creditcard',
        'braintree'         => 'creditcard',
        'square'            => 'creditcard',
        'authorize_net'     => 'creditcard',
        'adyen'             => 'creditcard',
        'worldpay'          => 'creditcard',
        'razorpay'          => 'creditcard',

        // PayPal
        'paypal'            => 'paypal',
        'paypal_express'    => 'paypal',

        // Cash / COD
        'cash_on_delivery'  => 'cash',
        'cod'               => 'cash',
        'cash'              => 'cash',

        // Bank transfers
        'bank_transfer'     => 'banktransfer',
        'wire'              => 'banktransfer',
        'ach'               => 'banktransfer',
        'bank_deposit'      => 'banktransfer',
        'upi'               => 'banktransfer',
        'net_banking'       => 'banktransfer',

        // Check
        'check'             => 'check',
        'cheque'            => 'check',

        // Manual / Other
        'manual'            => 'others',
        'gift_card'         => 'others',
        'exchange'          => 'others',
        'store_credit'      => 'others',
        'money_order'       => 'others',
    ];

    /**
     * Human-readable labels for Shopify gateways.
     */
    private const GATEWAY_LABELS = [
        'shopify_payments'  => 'Shopify Payments',
        'stripe'            => 'Stripe',
        'paypal'            => 'PayPal',
        'paypal_express'    => 'PayPal Express',
        'cash_on_delivery'  => 'Cash on Delivery',
        'cod'               => 'Cash on Delivery',
        'bank_transfer'     => 'Bank Transfer',
        'manual'            => 'Manual',
        'bogus'             => 'Bogus (Test)',
        'bogus_gateway'     => 'Bogus Gateway (Test)',
        'razorpay'          => 'Razorpay',
        'braintree'         => 'Braintree',
        'square'            => 'Square',
        'authorize_net'     => 'Authorize.net',
        'upi'               => 'UPI',
        'gift_card'         => 'Gift Card',
    ];

    /**
     * Resolve Zoho payment details from Shopify payment context.
     *
     * @param array $context {
     *     @type string|null $gateway       Shopify payment gateway identifier
     *     @type float       $amount        Payment amount
     *     @type string      $currency      Payment currency code
     *     @type string|null $transaction_kind  sale, authorization, capture, void, refund
     * }
     * @return array {
     *     @type string      $payment_mode       Zoho payment mode
     *     @type string|null $account_id          Zoho deposit account ID (null = Zoho default)
     *     @type string      $normalized_gateway  Normalized gateway name
     *     @type string      $gateway_label       Human-readable gateway label
     *     @type string      $source              'automatic' — indicates this was auto-resolved
     * }
     */
    public function resolveZohoPaymentDetails(array $context): array
    {
        $rawGateway = strtolower(trim((string) ($context['gateway'] ?? '')));

        // Resolve payment mode from deterministic map
        $paymentMode = self::GATEWAY_MAP[$rawGateway] ?? null;

        if ($paymentMode === null) {
            // Unknown gateway: safe fallback to 'others', log for review
            if (!empty($rawGateway)) {
                Log::warning("PaymentResolverService: Unknown Shopify payment gateway '{$rawGateway}'. Falling back to 'others'. Consider adding this gateway to the resolver map.", [
                    'gateway' => $rawGateway,
                    'amount' => $context['amount'] ?? null,
                    'currency' => $context['currency'] ?? null,
                ]);
            }

            $paymentMode = empty($rawGateway) ? 'creditcard' : 'others';
        }

        $gatewayLabel = self::GATEWAY_LABELS[$rawGateway] ?? ucwords(str_replace('_', ' ', $rawGateway ?: 'Unknown'));

        return [
            'payment_mode'       => $paymentMode,
            'account_id'         => null, // Let Zoho use its default account
            'normalized_gateway' => $rawGateway ?: 'unknown',
            'gateway_label'      => $gatewayLabel,
            'source'             => 'automatic',
        ];
    }

    /**
     * Check if a Shopify transaction kind represents a successful payment capture.
     */
    public function isPaymentCapture(?string $kind): bool
    {
        return in_array(strtolower(trim((string) $kind)), ['sale', 'capture'], true);
    }

    /**
     * Check if a Shopify transaction kind represents a refund.
     */
    public function isRefund(?string $kind): bool
    {
        return strtolower(trim((string) $kind)) === 'refund';
    }

    /**
     * Check if a Shopify transaction kind represents a void.
     */
    public function isVoid(?string $kind): bool
    {
        return strtolower(trim((string) $kind)) === 'void';
    }

    /**
     * Check if a Shopify transaction kind represents an authorization (not yet captured).
     */
    public function isAuthorization(?string $kind): bool
    {
        return strtolower(trim((string) $kind)) === 'authorization';
    }

    /**
     * Get all known gateway mappings (for diagnostics/dashboard display).
     */
    public function getKnownGateways(): array
    {
        $result = [];
        foreach (self::GATEWAY_MAP as $gateway => $mode) {
            $result[] = [
                'gateway' => $gateway,
                'label' => self::GATEWAY_LABELS[$gateway] ?? ucwords(str_replace('_', ' ', $gateway)),
                'payment_mode' => $mode,
            ];
        }
        return $result;
    }
}
