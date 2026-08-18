# Order Status Synchronization Assessment

## 1. Executive Summary & Context
This assessment details the order lifecycle synchronization between Shopify and Zoho Books. The application syncs Shopify orders, invoices, and payment transactions to Zoho Books REST API v3 while maintaining multi-tenant isolation and strict webhook idempotency.

---

## 2. Current Order Synchronization Flow
1. **Order Creation / Update**:
   - Webhook Topic: `orders/create`, `orders/updated` handled by `ShopifyWebhookController::ordersUpdate()`.
   - Action: Validates HMAC and tenant domain (`Shop`), checks idempotency (`ShopifyProcessedWebhook`), saves/updates local `Order` record, and syncs associated `Customer`.
   - Zoho Dispatch:
     - Calls `ZohoService::syncOrder($order)`: Creates (`POST /books/v3/salesorders`) or updates (`PUT /books/v3/salesorders/{id}`) a Zoho Sales Order.
     - Calls `ZohoService::syncInvoice($order)`: Creates (`POST /books/v3/invoices`) or updates (`PUT /books/v3/invoices/{id}`) a Zoho Invoice.

2. **Payment Creation**:
   - Webhook Topic: `order_transactions/create` handled by `ShopifyWebhookController::orderTransactionsCreate()`.
   - Action: Validates transaction eligibility (`kind` in `['sale', 'capture']`, `status === 'success'`), creates/updates local `Payment` record with `status = 'paid'`.
   - Zoho Dispatch:
     - Calls `ZohoService::syncPayment($payment)`: Posts customer payment (`POST /books/v3/customerpayments`) linked to the Zoho Invoice ID.
     - Automatically updates Zoho Invoice status to `paid` upon applying the payment.

---

## 3. Order Lifecycle Status Mapping & Current State

| Lifecycle State | Shopify Status / Indicator | Relevant Webhook(s) | Local DB Model State | Zoho Object Affected & API Action | Current Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Pending** | `financial_status` in `['pending', 'authorized']` | `orders/create`, `orders/updated` | `Order`: `financial_status = 'pending'`, `fulfillment_status = 'unfulfilled'` | `salesorders` (Draft/Open Sales Order), `invoices` (Draft/Sent Invoice) | **Fully Synchronized** |
| **2. Confirmed** | Order placed / confirmed in Shopify | `orders/create`, `orders/updated` | `Order`: record exists and mapped to Zoho IDs | `salesorders` (Sales Order created/updated in Zoho) | **Fully Synchronized** |
| **3. Paid** | `financial_status = 'paid'` / transaction success | `order_transactions/create`, `orders/updated` | `Order`: `financial_status = 'paid'`; `Payment`: `status = 'paid'`, `sync_status = 'synced'` | `customerpayments` (`POST /books/v3/customerpayments`), `invoices` (marked Paid in Zoho) | **Fully Synchronized** |
| **4. Cancelled** | `cancelled_at` set or `financial_status = 'voided'` / order cancelled | `orders/updated` | `Order`: `financial_status = 'voided'` or `'cancelled'` | `salesorders` (`POST /books/v3/salesorders/{id}/status/void`), `invoices` (`POST /books/v3/invoices/{id}/status/void`) | **Partially Implemented** (Status updated locally, Zoho void API missing) |
| **5. Refunded** | `financial_status` in `['refunded', 'partially_refunded']` | `orders/updated`, `order_transactions/create` (`kind = refund`) | `Order`: `financial_status = 'refunded'`; `Payment` skips refund txns per design | `invoices` / `customerpayments` (Refund transaction handling) | **Ambiguous / Intentionally Deferred** (Refund transactions explicitly skipped in `orderTransactionsCreate` test #12; no Credit Note logic in Zoho API service) |
| **6. Fulfilled** | `fulfillment_status` in `['fulfilled', 'partial']` | `orders/updated` | `Order`: `fulfillment_status = 'fulfilled'` | `salesorders` (`POST /books/v3/salesorders/{id}/status/fulfilled` or status transition) | **Partially Implemented** (Stored locally, Zoho Sales Order status update missing) |

---

## 4. Identified Missing Functionality
1. **Order Cancellation & Voiding in Zoho**:
   - When an order is cancelled in Shopify (`cancelled_at` present or `financial_status` is `voided` / `cancelled`), local `Order` status is updated, but Zoho Sales Order and Invoice remain open/draft in Zoho Books.
   - Requirement: `ZohoService` needs methods `voidSalesOrder(string $zohoSalesOrderId)` and `voidInvoice(string $zohoInvoiceId)` using endpoints:
     - `POST /books/v3/salesorders/{salesorder_id}/status/void`
     - `POST /books/v3/invoices/{invoice_id}/status/void`
   - Trigger: `ZohoService::syncOrder()` and `ZohoService::syncInvoice()` (or `ordersUpdate()` handler) should check if order is cancelled and execute void status requests in Zoho Books.

2. **Order Fulfillment Status in Zoho**:
   - When Shopify `fulfillment_status` changes to `fulfilled`, local `Order` table updates `fulfillment_status`.
   - Requirement: Zoho Sales Orders can be marked as fulfilled or open in Zoho Books via `POST /books/v3/salesorders/{salesorder_id}/status/fulfilled` or updating salesorder status if supported.

3. **Refund Handling (Business Rule Ambiguity)**:
   - `ShopifyOrderTransactionWebhookTest` explicitly tests that refund transactions (`kind` = `refund`) are skipped by `orderTransactionsCreate` because `/books/v3/customerpayments` only accepts payments.
   - Zoho Books handles refunds via Credit Notes (`/books/v3/creditnotes`) or Payment Refunds (`/books/v3/customerpayments/{id}/refunds`). Since no Credit Note schema or business rules exist in the current codebase, refund updates mark local `Order.financial_status = 'refunded'` and skip payment sync without failing webhooks.

---

## 5. Potential Webhook & Idempotency Considerations
1. **Duplicate Webhook Deliveries**:
   - Managed via `ShopifyProcessedWebhook` table (`X-Shopify-Webhook-Id` + `shop_domain`).
   - Secondary layer: `Order::updateOrCreate` by `shop_id` + `shopify_order_id`, and `findZohoSalesOrderByReferenceNumber` / `findZohoInvoiceByReferenceNumber` lookups before creation.
2. **Repeated Cancellation Webhooks**:
   - Voiding an already voided Sales Order or Invoice in Zoho returns Zoho code 0 or an item already voided message. `ZohoService` void methods must gracefully handle 200/400 responses where status is already voided.
3. **Tenant Isolation**:
   - All lookups, database queries, and Zoho API calls strictly use `$shop->id` and `$shop->zohoConnection`.

---

## 6. Recommended Implementation Plan
1. Implement `voidSalesOrder()` and `voidInvoice()` in `ZohoService.php` to handle Zoho API calls for cancelled orders.
2. Update `syncOrder()` and `syncInvoice()` in `ZohoService.php` to detect cancelled orders and call void endpoints.
3. Update `syncOrder()` in `ZohoService.php` to mark Sales Orders as fulfilled in Zoho Books when `fulfillment_status` is `fulfilled`.
4. Add unit test coverage in `tests/Unit/ZohoOrderSyncTest.php` for order cancellation, voiding in Zoho, and fulfillment status update.
5. Verify complete PHPUnit test suite execution.
