# Section J — Tax Mapping Assessment & Technical Audit

## Executive Summary
This document provides a comprehensive audit of the current tax handling mechanisms within the Shopify to Zoho Books integration application. It evaluates data ingestion from Shopify, internal persistence models, mapping configurations, payload formatting for Zoho Sales Orders and Invoices, and details the technical strategy required to fully implement **Section J: Tax Mapping**.

---

## 1. Current Tax Data Flow Analysis

### 1.1 Shopify → Application Ingestion
* **GraphQL Order Fetching (`ShopifyService::fetchOrders`, `fetchAndSyncOrder`)**:
  * Currently queries `totalTaxSet { shopMoney { amount } }`.
  * Extracts aggregate `total_tax` and persists it to `orders.tax_total`.
  * **Gap**: Line-item level `tax_lines`, shipping `tax_lines`, and order-level `taxes_included` boolean are **not** currently queried or extracted from GraphQL responses.
* **REST Webhooks (`ShopifyWebhookController::ordersUpdate`)**:
  * Extracts `total_tax` from `payload['total_tax']`.
  * Persists `tax_total` to `orders.tax_total`.
  * **Gap**: Line item `tax_lines`, shipping `tax_lines`, and `taxes_included` flags present in the webhook payload are **not** parsed or stored in line items or order attributes.

### 1.2 Application → Zoho Synchronization
* **Sales Order Payload (`ZohoService::syncOrder`)**:
  * Hardcodes `$payload['is_discount_before_tax'] = true`.
  * Sets `$payload['tax_total'] = (float) $order->tax_total` if `tax_total > 0`.
  * **Gap**: Line items do **not** include `tax_id`, `tax_name`, or `tax_percentage`. Zoho Books relies on line-item tax details or explicit tax mode (`is_inclusive_tax`) to calculate header totals accurately.
* **Invoice Payload (`ZohoService::syncInvoice`)**:
  * Hardcodes `'is_discount_before_tax' => true`.
  * Sets `shipping_charge` and `discount`.
  * **Gap**: Completely omits `tax_total` and line-item tax fields (`tax_id`, `tax_name`, `tax_percentage`). If Zoho items do not have default tax rules, Zoho Invoices generate with $0.00 tax despite Shopify specifying taxes.

---

## 2. Technical Audit by Requirement

| Requirement | Current Status | Audit Findings & Required Changes |
| :--- | :--- | :--- |
| **1. GST / VAT Mapping** | Missing | No UI or backend setting exists to map Shopify tax labels (e.g. "GST 5%", "VAT 20%") to Zoho Tax IDs. Need mapping table & API integration with Zoho `/books/v3/settings/taxes`. |
| **2. Tax-Inclusive Pricing** | Partial / Implicit | Shopify `taxes_included` flag is not captured. Zoho requires `is_inclusive_tax: true` on Sales Order / Invoice payloads when prices include tax. |
| **3. Tax-Exclusive Pricing** | Default | Zoho defaults to tax-exclusive. Explicit setting needed in payload to avoid double-taxing or miscalculating net totals. |
| **4. Shipping Tax** | Missing | `shipping_total` is sent as `shipping_charge`, but shipping tax lines from Shopify are ignored. Must support mapping shipping tax or preserving order tax allocation. |
| **5. Discount Tax Handling** | Hardcoded | `is_discount_before_tax` is hardcoded to `true`. Must support configurable discount tax behavior (`before_tax` vs `after_tax`). |

---

## 3. Database & Architecture Assessment

* **Shops Table Architecture**:
  * Currently contains `payment_gateway_settings` (JSON cast).
  * Recommended approach: Add a `tax_settings` column (JSON cast) on the `shops` table.
  * This preserves multi-tenant isolation, avoids redundant database tables, and fits seamlessly into the existing `Shop` model and Settings architecture.

### Config Structure (`tax_settings` JSON schema):
```json
{
  "tax_mode": "exclusive",
  "default_tax_id": "",
  "default_tax_name": "No Tax (0%)",
  "default_tax_percentage": 0,
  "shipping_tax_mode": "use_order_tax",
  "discount_tax_mode": "before_tax",
  "tax_mappings": [
    {
      "shopify_tax_name": "GST",
      "shopify_rate": 5,
      "zoho_tax_id": "460000000012345",
      "zoho_tax_name": "GST 5%"
    }
  ]
}
```

---

## 4. Recommended Implementation Strategy

1. **Database Migration**: Add `tax_settings` (JSON, nullable) to `shops` table. Update `Shop` model with fillable & cast array.
2. **Zoho Taxes API Integration**: Implement `getZohoTaxes()` in `ZohoService` to fetch available tax rates from `GET /books/v3/settings/taxes`.
3. **Data Ingestion Enhancement**: Update `ShopifyService` and `ShopifyWebhookController` to extract `tax_lines`, `taxes_included`, and shipping tax lines.
4. **Order & Invoice Payload Logic**:
   * Inspect shop's `tax_settings` and order's `taxes_included` / `tax_lines`.
   * Map line items to their respective `zoho_tax_id` or `tax_name`.
   * Set `is_inclusive_tax` flag based on Shopify `taxes_included` or shop tax mode preference.
   * Format `shipping_charge` and shipping tax appropriately.
5. **Polaris Settings UI**:
   * Add a "Tax Configuration" card to `Settings.jsx` utilizing real Shopify Polaris components (`Card`, `Select`, `TextField`, `Button`, `Banner`, `InlineStack`, `BlockStack`).
   * Provide intuitive tax selection dropdowns populated with merchant's actual Zoho Tax rates.
6. **Testing Suite**:
   * Unit tests for tax mapping, inclusive/exclusive calculation, shipping tax, discount tax, and tenant isolation in `ZohoOrderSyncTest` & `ZohoInvoiceSyncTest`.

---

## 5. Risk Analysis & Edge Cases

* **Tax Rounding Differences**: Shopify calculates tax per line item rounded to 2 decimals, whereas Zoho may calculate tax at order total level. Passing line-item `tax_id` and explicit tax rates ensures Zoho matches Shopify calculations.
* **Multiple Tax Rates per Order**: Orders containing items taxable at different rates (e.g. 5% GST and 18% GST) require line-item tax assignment rather than order-level tax header assignment.
* **Zero-Tax Orders / Exempt Customers**: Zero-tax orders must map cleanly to Zoho's "No Tax" (0%) or omit `tax_id` without causing Zoho payload validation errors.
