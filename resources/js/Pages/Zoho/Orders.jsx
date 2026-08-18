import React, { useEffect, useState } from "react";
import {
    Page,
    Card,
    Tabs,
    IndexTable,
    Badge,
    Banner,
    Button,
    Popover,
    ActionList,
    TextField,
    Modal,
    Text,
    Box,
    BlockStack,
    InlineStack,
    Spinner,
    useIndexResourceState,
} from "@shopify/polaris";
import ZohoLayout from "@/Layouts/ZohoLayout";

const ORDERS_DATA_URL = "/api/zoho/orders";
const SYNC_ORDER_URL = "/zoho/sync-order";
const SYNC_INVOICE_URL = "/zoho/sync-invoice";
const SYNC_PAYMENT_URL = "/zoho/sync-payment";
const BULK_SYNC_ORDERS_URL = "/zoho/bulk-sync-orders";

export default function Orders({
    shop,
    orders = [],
    zohoConnected = false,
    host = "",
}) {
    const [initialLoading, setInitialLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [orderList, setOrderList] = useState(orders || []);
    const [search, setSearch] = useState("");
    const [selectedTab, setSelectedTab] = useState(0);
    const [syncingOrderId, setSyncingOrderId] = useState(null);
    const [syncingPaymentId, setSyncingPaymentId] = useState(null);
    const [syncType, setSyncType] = useState(null);
    const [notification, setNotification] = useState(null);
    const [selectedOrderForPayment, setSelectedOrderForPayment] = useState(null);
    const [openActionMenuId, setOpenActionMenuId] = useState(null);

    // Bulk State
    const [bulkSyncing, setBulkSyncing] = useState(false);
    const [bulkResultsModal, setBulkResultsModal] = useState(null);

    const loadData = async (isRefresh = false) => {
        if (isRefresh) {
            setRefreshing(true);
        } else {
            setInitialLoading(true);
        }
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(ORDERS_DATA_URL, { headers });
            const data = await response.json();

            if (response.ok && data.success) {
                setOrderList(data.orders || []);
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean")
                    setConnectedState(data.zohoConnected);
            }
        } catch (error) {
            console.error("Failed to load orders:", error);
        } finally {
            setInitialLoading(false);
            setRefreshing(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    // Sync selected order in modal when orderList updates
    useEffect(() => {
        if (selectedOrderForPayment) {
            const updated = orderList.find((o) => o.id === selectedOrderForPayment.id);
            if (updated) {
                setSelectedOrderForPayment(updated);
            }
        }
    }, [orderList]);

    const handleSyncOrder = async (orderId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingOrderId(orderId);
        setSyncType("order");
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_ORDER_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ order_id: orderId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: data.message || "Sales Order synchronized successfully.",
                });
                await loadData(true);
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Sales Order creation failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during Sales Order sync.",
            });
        } finally {
            setSyncingOrderId(null);
            setSyncType(null);
        }
    };

    const handleSyncInvoice = async (orderId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingOrderId(orderId);
        setSyncType("invoice");
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_INVOICE_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ order_id: orderId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: data.message || "Invoice created/synchronized successfully.",
                });
                await loadData(true);
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Invoice creation failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during invoice sync.",
            });
        } finally {
            setSyncingOrderId(null);
            setSyncType(null);
        }
    };

    const handleSyncPayment = async (paymentId, orderId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingPaymentId(paymentId || orderId);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_PAYMENT_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({
                    payment_id: paymentId || null,
                    order_id: orderId || null,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: data.message || "Payment synchronized to Zoho successfully.",
                });
                await loadData(true);
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Payment sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during payment sync.",
            });
        } finally {
            setSyncingPaymentId(null);
        }
    };

    const getPaymentSummary = (order) => {
        const payments = order.payments || [];
        const total = parseFloat(order.total_price || 0);

        const paidSum = payments.reduce((sum, p) => {
            if (p.status === "paid" || p.sync_status === "synced") {
                return sum + parseFloat(p.amount || 0);
            }
            return sum;
        }, 0);

        const hasFailed = payments.some((p) => p.sync_status === "failed");
        const hasPending = payments.some((p) => p.sync_status === "pending");

        if (paidSum >= total && total > 0) {
            return {
                status: "paid",
                label: `Paid ($${total.toFixed(2)})`,
                tone: "success",
            };
        }

        if (paidSum > 0 && paidSum < total) {
            return {
                status: "partial",
                label: `$${paidSum.toFixed(2)} / $${total.toFixed(2)} Partial`,
                tone: "warning",
            };
        }

        if (hasFailed) {
            return {
                status: "failed",
                label: "Sync Failed",
                tone: "critical",
            };
        }

        if (order.financial_status === "refunded" || payments.some((p) => p.status === "refunded")) {
            return {
                status: "refunded",
                label: "Refunded",
                tone: "subdued",
            };
        }

        if (order.financial_status === "paid") {
            return {
                status: "paid_pending_sync",
                label: hasPending ? "Sync Pending" : "Paid (Shopify)",
                tone: "info",
            };
        }

        return {
            status: "pending",
            label: "Pending",
            tone: "warning",
        };
    };

    const tabKeys = ["all", "invoiced", "pending"];
    const currentTabKey = tabKeys[selectedTab] || "all";

    const filteredOrders = orderList.filter((o) => {
        const orderNum = (o.name || o.order_number || o.shopify_order_id || "")
            .toString()
            .toLowerCase();
        const custName = (
            o.customer
                ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`
                : ""
        ).toLowerCase();

        const matchesSearch =
            orderNum.includes(search.toLowerCase()) ||
            custName.includes(search.toLowerCase());
        if (!matchesSearch) return false;

        const hasInvoice = o.invoice && o.invoice.zoho_invoice_id;
        if (currentTabKey === "invoiced") return hasInvoice;
        if (currentTabKey === "pending") return !hasInvoice;

        return true;
    });

    const {
        selectedResources,
        allResourcesSelected,
        handleSelectionChange,
        clearSelection,
    } = useIndexResourceState(filteredOrders);

    const handleBulkSync = async (type = "order") => {
        if (selectedResources.length === 0) return;

        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setBulkSyncing(true);
        setSyncType(type);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(BULK_SYNC_ORDERS_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({
                    order_ids: selectedResources,
                    sync_type: type,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setBulkResultsModal({
                    summary: data.summary || {},
                    results: data.results || [],
                    syncType: type,
                });
                await loadData(true);
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Bulk order sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during bulk order sync.",
            });
        } finally {
            setBulkSyncing(false);
            setSyncType(null);
        }
    };

    const tabs = [
        { id: "all", content: `All (${orderList.length})` },
        {
            id: "invoiced",
            content: `Invoiced (${orderList.filter((o) => o.invoice?.zoho_invoice_id).length})`,
        },
        {
            id: "pending",
            content: `Pending Invoice (${orderList.filter((o) => !o.invoice?.zoho_invoice_id).length})`,
        },
    ];

    const promotedBulkActions = [
        {
            content: "Sync Sales Orders",
            onAction: () => handleBulkSync("order"),
            disabled: bulkSyncing,
        },
        {
            content: "Sync Invoices",
            onAction: () => handleBulkSync("invoice"),
            disabled: bulkSyncing,
        },
        {
            content: "Sync Payments",
            onAction: () => handleBulkSync("payment"),
            disabled: bulkSyncing,
        },
    ];

    const headings = [
        { title: "Order #" },
        { title: "Customer" },
        { title: "Date" },
        { title: "Total" },
        { title: "Zoho Sales Order" },
        { title: "Zoho Invoice" },
        { title: "Invoice Status" },
        { title: "Payment" },
        { title: "Actions", alignment: "end" },
    ];

    const rowMarkup = filteredOrders.map((o, index) => {
        const isSyncing = syncingOrderId === o.id;
        const isSelected = selectedResources.includes(o.id);
        const hasInvoice = !!o.invoice?.zoho_invoice_id;
        const paySummary = getPaymentSummary(o);
        const isMenuOpen = openActionMenuId === o.id;

        return (
            <IndexTable.Row
                id={o.id}
                key={o.id}
                selected={isSelected}
                position={index}
            >
                <IndexTable.Cell>
                    <Text variant="bodyMd" fontWeight="bold" as="span">
                        <span style={{ color: "#005bd3" }}>
                            {o.name || (o.order_number ? `#${o.order_number}` : `#${o.shopify_order_id}`)}
                        </span>
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {o.customer ? (
                        <BlockStack gap="050">
                            <Text variant="bodyMd" fontWeight="semibold" as="span">
                                {o.customer.first_name} {o.customer.last_name}
                            </Text>
                            <Text variant="bodySm" tone="subdued" as="span">
                                {o.customer.email}
                            </Text>
                        </BlockStack>
                    ) : (
                        <Text variant="bodyMd" tone="subdued" as="span">
                            Guest Customer
                        </Text>
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Text variant="bodySm" tone="subdued" as="span">
                        {o.created_at ? new Date(o.created_at).toLocaleDateString() : "—"}
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Text variant="bodyMd" fontWeight="bold" as="span">
                        ${parseFloat(o.total_price || 0).toFixed(2)}{" "}
                        <Text variant="bodySm" tone="subdued" as="span">
                            {o.currency || "USD"}
                        </Text>
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {o.zoho_sales_order_number || o.zoho_sales_order_id ? (
                        <Text variant="bodySm" tone="subdued" as="span">
                            <code style={{ fontSize: "11px", backgroundColor: "#f1f2f4", padding: "2px 6px", borderRadius: "4px", color: "#616a75" }}>
                                {o.zoho_sales_order_number || (o.zoho_sales_order_id?.startsWith("SO-") ? o.zoho_sales_order_id : `SO-${o.zoho_sales_order_id}`)}
                            </code>
                        </Text>
                    ) : (
                        <Text variant="bodySm" tone="subdued" as="span">—</Text>
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {o.invoice?.zoho_invoice_id ? (
                        <Text variant="bodySm" tone="subdued" as="span">
                            <code style={{ fontSize: "11px", backgroundColor: "#f1f2f4", padding: "2px 6px", borderRadius: "4px", color: "#616a75" }}>
                                INV-{o.invoice.zoho_invoice_id}
                            </code>
                        </Text>
                    ) : (
                        <Text variant="bodySm" tone="subdued" as="span">—</Text>
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Badge tone={hasInvoice ? "success" : "warning"}>
                        {hasInvoice ? "Invoiced" : "Pending Invoice"}
                    </Badge>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <div onClick={(e) => e.stopPropagation()}>
                        <Button
                            size="micro"
                            onClick={() => setSelectedOrderForPayment(o)}
                        >
                            <Badge tone={paySummary.tone}>{paySummary.label}</Badge>
                        </Button>
                    </div>
                </IndexTable.Cell>
                <IndexTable.Cell alignment="end">
                    <div onClick={(e) => e.stopPropagation()}>
                        <Popover
                            active={isMenuOpen}
                            activator={
                                <Button
                                    size="slim"
                                    onClick={() => setOpenActionMenuId(isMenuOpen ? null : o.id)}
                                    disabled={isSyncing || bulkSyncing}
                                    disclosure
                                >
                                    {isSyncing ? "Syncing..." : "Actions"}
                                </Button>
                            }
                            onClose={() => setOpenActionMenuId(null)}
                        >
                            <ActionList
                                actionRole="menuitem"
                                items={[
                                    {
                                        content: "Sync Sales Order",
                                        onAction: () => {
                                            setOpenActionMenuId(null);
                                            handleSyncOrder(o.id);
                                        },
                                    },
                                    {
                                        content: "Sync Invoice",
                                        onAction: () => {
                                            setOpenActionMenuId(null);
                                            handleSyncInvoice(o.id);
                                        },
                                    },
                                    {
                                        content: "View Payment",
                                        onAction: () => {
                                            setOpenActionMenuId(null);
                                            setSelectedOrderForPayment(o);
                                        },
                                    },
                                ]}
                            />
                        </Popover>
                    </div>
                </IndexTable.Cell>
            </IndexTable.Row>
        );
    });

    return (
        <ZohoLayout
            title="Orders & Invoices | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="orders"
        >
            <Page
                fullWidth
                title="Orders & Invoices"
                subtitle="Manage Shopify orders, invoices, and payment synchronization to Zoho Books."
                primaryAction={{
                    content: "Refresh",
                    onAction: () => loadData(true),
                    loading: refreshing,
                    disabled: refreshing || bulkSyncing,
                }}
            >
                <BlockStack gap="400">
                    {/* NOTIFICATION BANNER */}
                    {notification && (
                        <Banner
                            tone={notification.type === "success" ? "success" : "critical"}
                            onDismiss={() => setNotification(null)}
                        >
                            <p>{notification.message}</p>
                        </Banner>
                    )}

                    <Card padding="0">
                        <Tabs tabs={tabs} selected={selectedTab} onSelect={setSelectedTab}>
                            <Box padding="400">
                                <TextField
                                    label="Search orders"
                                    labelHidden
                                    placeholder="Search order #, customer..."
                                    value={search}
                                    onChange={setSearch}
                                    autoComplete="off"
                                    clearButton
                                    onClearButtonClick={() => setSearch("")}
                                />
                            </Box>
                            {initialLoading ? (
                                <Box padding="800">
                                    <BlockStack align="center" inlineAlign="center" gap="300">
                                        <Spinner accessibilityLabel="Loading orders" size="large" />
                                        <Text tone="subdued" variant="bodyMd" as="p">
                                            Loading orders and invoices...
                                        </Text>
                                    </BlockStack>
                                </Box>
                            ) : (
                                <IndexTable
                                    resourceName={{ singular: "order", plural: "orders" }}
                                    itemCount={filteredOrders.length}
                                    selectedItemsCount={
                                        allResourcesSelected ? "All" : selectedResources.length
                                    }
                                    onSelectionChange={handleSelectionChange}
                                    headings={headings}
                                    promotedBulkActions={promotedBulkActions}
                                    loading={false}
                                >
                                    {rowMarkup}
                                </IndexTable>
                            )}
                        </Tabs>
                    </Card>
                </BlockStack>

                {/* BULK RESULTS MODAL */}
                {bulkResultsModal && (
                    <Modal
                        open={!!bulkResultsModal}
                        onClose={() => {
                            setBulkResultsModal(null);
                            clearSelection();
                        }}
                        title={`Bulk Synchronization Results (${bulkResultsModal.syncType?.toUpperCase() || "ORDER"})`}
                        primaryAction={{
                            content: "Close & Refresh",
                            onAction: () => {
                                setBulkResultsModal(null);
                                clearSelection();
                            },
                        }}
                    >
                        <Modal.Section>
                            <BlockStack gap="300">
                                <InlineStack gap="200">
                                    <Badge>Total: {bulkResultsModal.summary?.total || 0}</Badge>
                                    <Badge tone="success">Synced: {bulkResultsModal.summary?.synced || 0}</Badge>
                                    <Badge tone="critical">Failed: {bulkResultsModal.summary?.failed || 0}</Badge>
                                    {bulkResultsModal.summary?.skipped > 0 && (
                                        <Badge tone="warning">Skipped: {bulkResultsModal.summary?.skipped}</Badge>
                                    )}
                                </InlineStack>

                                <Box borderWidth="025" borderColor="border" borderRadius="200" overflowX="auto">
                                    <table style={{ width: "100%", borderCollapse: "collapse", fontSize: "13px" }}>
                                        <thead>
                                            <tr style={{ backgroundColor: "#f8f9fa", borderBottom: "1px solid #e1e3e5", textAlign: "left" }}>
                                                <th style={{ padding: "10px 14px" }}>Order # / ID</th>
                                                <th style={{ padding: "10px 14px" }}>Status</th>
                                                <th style={{ padding: "10px 14px" }}>Message</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {bulkResultsModal.results?.map((res, idx) => (
                                                <tr key={idx} style={{ borderBottom: "1px solid #f1f2f4" }}>
                                                    <td style={{ padding: "10px 14px", fontWeight: 600, fontFamily: "monospace" }}>
                                                        {res.order_number ? `#${res.order_number}` : `ID #${res.id}`}
                                                    </td>
                                                    <td style={{ padding: "10px 14px" }}>
                                                        <Badge tone={res.status === "success" ? "success" : res.status === "skipped" ? "warning" : "critical"}>
                                                            {res.status}
                                                        </Badge>
                                                    </td>
                                                    <td style={{ padding: "10px 14px", color: "#616a75" }}>
                                                        {res.message}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </Box>
                            </BlockStack>
                        </Modal.Section>
                    </Modal>
                )}

                {/* PAYMENT DETAILS MODAL */}
                {selectedOrderForPayment && (
                    <Modal
                        open={!!selectedOrderForPayment}
                        onClose={() => setSelectedOrderForPayment(null)}
                        title={`Payment Details — ${selectedOrderForPayment.name || `#${selectedOrderForPayment.order_number}`}`}
                        primaryAction={{
                            content: "Close",
                            onAction: () => setSelectedOrderForPayment(null),
                        }}
                    >
                        <Modal.Section>
                            <BlockStack gap="400">
                                <Text tone="subdued" as="p">
                                    Associated Invoice:{" "}
                                    <strong style={{ color: "#005bd3", fontFamily: "monospace" }}>
                                        {selectedOrderForPayment.invoice?.zoho_invoice_id
                                            ? `INV-${selectedOrderForPayment.invoice.zoho_invoice_id}`
                                            : "Pending Invoice"}
                                    </strong>
                                </Text>

                                <InlineStack gap="400">
                                    <Box padding="300" background="bg-surface-secondary" borderRadius="200" style={{ flex: 1 }}>
                                        <Text tone="subdued" variant="bodySm" as="p">Order Total</Text>
                                        <Text variant="headingSm" as="p">
                                            ${parseFloat(selectedOrderForPayment.total_price || 0).toFixed(2)} {selectedOrderForPayment.currency || "USD"}
                                        </Text>
                                    </Box>
                                    <Box padding="300" background="bg-surface-secondary" borderRadius="200" style={{ flex: 1 }}>
                                        <Text tone="subdued" variant="bodySm" as="p">Financial Status</Text>
                                        <Text variant="headingSm" as="p" style={{ textTransform: "capitalize" }}>
                                            {selectedOrderForPayment.financial_status || "pending"}
                                        </Text>
                                    </Box>
                                    <Box padding="300" background="bg-surface-secondary" borderRadius="200" style={{ flex: 1 }}>
                                        <Text tone="subdued" variant="bodySm" as="p">Shipping Charge</Text>
                                        <Text variant="headingSm" as="p">
                                            {parseFloat(selectedOrderForPayment.shipping_total || 0) > 0 
                                                ? `$${parseFloat(selectedOrderForPayment.shipping_total).toFixed(2)} ${selectedOrderForPayment.currency || "USD"}` 
                                                : "Free Shipping"}
                                        </Text>
                                    </Box>
                                </InlineStack>

                                {/* SHIPPING & TRACKING SECTION */}
                                <Card>
                                    <BlockStack gap="300">
                                        <Text variant="headingSm" as="h3">
                                            🚚 Shipping &amp; Tracking Details
                                        </Text>
                                        <InlineStack gap="400" wrap={false}>
                                            <Box style={{ flex: 1 }}>
                                                <Text tone="subdued" variant="bodySm" as="p">Shipping Method</Text>
                                                <Text variant="bodyMd" fontWeight="semibold" as="p">
                                                    {selectedOrderForPayment.shipping_method || "Standard Delivery"}
                                                </Text>
                                            </Box>
                                            <Box style={{ flex: 1 }}>
                                                <Text tone="subdued" variant="bodySm" as="p">Carrier / Courier</Text>
                                                <Text variant="bodyMd" fontWeight="semibold" as="p">
                                                    {selectedOrderForPayment.tracking_company || "Not Specified"}
                                                </Text>
                                            </Box>
                                            <Box style={{ flex: 1 }}>
                                                <Text tone="subdued" variant="bodySm" as="p">Tracking Number</Text>
                                                {selectedOrderForPayment.tracking_number ? (
                                                    selectedOrderForPayment.tracking_url ? (
                                                        <a href={selectedOrderForPayment.tracking_url} target="_blank" rel="noopener noreferrer" style={{ color: "#005bd3", fontWeight: 600, fontSize: "13px" }}>
                                                            #{selectedOrderForPayment.tracking_number} 🔗
                                                        </a>
                                                    ) : (
                                                        <Text variant="bodyMd" fontWeight="semibold" as="p">
                                                            #{selectedOrderForPayment.tracking_number}
                                                        </Text>
                                                    )
                                                ) : (
                                                    <Text tone="subdued" variant="bodyMd" as="p">No tracking yet</Text>
                                                )}
                                            </Box>
                                        </InlineStack>

                                        {selectedOrderForPayment.shipping_address && typeof selectedOrderForPayment.shipping_address === "object" && (
                                            <Box padding="300" background="bg-surface-secondary" borderRadius="200" marginTop="200">
                                                <Text tone="subdued" variant="bodySm" as="p" fontWeight="semibold">Shipping Destination:</Text>
                                                <Text variant="bodySm" as="p">
                                                    {selectedOrderForPayment.shipping_address.first_name || selectedOrderForPayment.shipping_address.name} {selectedOrderForPayment.shipping_address.last_name || ""}
                                                    {selectedOrderForPayment.shipping_address.company ? ` (${selectedOrderForPayment.shipping_address.company})` : ""}
                                                </Text>
                                                <Text variant="bodySm" tone="subdued" as="p">
                                                    {[
                                                        selectedOrderForPayment.shipping_address.address1,
                                                        selectedOrderForPayment.shipping_address.address2,
                                                        selectedOrderForPayment.shipping_address.city,
                                                        selectedOrderForPayment.shipping_address.province || selectedOrderForPayment.shipping_address.state,
                                                        selectedOrderForPayment.shipping_address.zip,
                                                        selectedOrderForPayment.shipping_address.country
                                                    ].filter(Boolean).join(", ")}
                                                </Text>
                                                {selectedOrderForPayment.shipping_address.phone && (
                                                    <Text variant="bodySm" tone="subdued" as="p">
                                                        📞 {selectedOrderForPayment.shipping_address.phone}
                                                    </Text>
                                                )}
                                            </Box>
                                        )}
                                    </BlockStack>
                                </Card>

                                <Text variant="headingSm" as="h3">
                                    Payment Transactions ({selectedOrderForPayment.payments ? selectedOrderForPayment.payments.length : 0})
                                </Text>

                                {!selectedOrderForPayment.payments || selectedOrderForPayment.payments.length === 0 ? (
                                    <Box padding="400" background="bg-surface-secondary" borderRadius="200">
                                        <Text tone="subdued" as="p">
                                            No payment transaction recorded locally for this order.
                                        </Text>
                                        {selectedOrderForPayment.invoice && (
                                            <Box paddingTop="200">
                                                <Button
                                                    variant="primary"
                                                    onClick={() => handleSyncPayment(null, selectedOrderForPayment.id)}
                                                    loading={syncingPaymentId === selectedOrderForPayment.id}
                                                >
                                                    Record &amp; Sync Payment to Zoho
                                                </Button>
                                            </Box>
                                        )}
                                    </Box>
                                ) : (
                                    <BlockStack gap="300">
                                        {selectedOrderForPayment.payments.map((p) => {
                                            const isPaymentSyncing = syncingPaymentId === p.id || syncingPaymentId === selectedOrderForPayment.id;
                                            const isSynced = p.sync_status === "synced" || !!p.zoho_payment_id;
                                            const isFailed = p.sync_status === "failed";

                                            return (
                                                <Card key={p.id}>
                                                    <BlockStack gap="200">
                                                        <InlineStack align="space-between">
                                                            <Text variant="headingSm" as="span">
                                                                ${parseFloat(p.amount || 0).toFixed(2)} {p.currency || "USD"}
                                                            </Text>
                                                            <Badge tone={isSynced ? "success" : isFailed ? "critical" : "warning"}>
                                                                Sync: {p.sync_status ? p.sync_status.toUpperCase() : "PENDING"}
                                                            </Badge>
                                                        </InlineStack>

                                                        <Text variant="bodySm" tone="subdued" as="p">
                                                            Method: {p.payment_method || "shopify_payments"} | Date: {p.payment_date ? new Date(p.payment_date).toLocaleString() : "—"}
                                                        </Text>
                                                        <Text variant="bodySm" tone="subdued" as="p">
                                                            Shopify Txn: <code>{p.shopify_transaction_id || p.payment_reference || "—"}</code> | Zoho Payment ID: <code>{p.zoho_payment_id || "Not Synced"}</code>
                                                        </Text>

                                                        {isFailed && p.error_message && (
                                                            <Banner tone="critical">
                                                                <p>{p.error_message}</p>
                                                            </Banner>
                                                        )}

                                                        {(!isSynced || isFailed) && (
                                                            <InlineStack align="end">
                                                                <Button
                                                                    variant="primary"
                                                                    size="slim"
                                                                    onClick={() => handleSyncPayment(p.id, selectedOrderForPayment.id)}
                                                                    loading={isPaymentSyncing}
                                                                >
                                                                    Retry Payment
                                                                </Button>
                                                            </InlineStack>
                                                        )}
                                                    </BlockStack>
                                                </Card>
                                            );
                                        })}
                                    </BlockStack>
                                )}
                            </BlockStack>
                        </Modal.Section>
                    </Modal>
                )}
            </Page>
        </ZohoLayout>
    );
}
