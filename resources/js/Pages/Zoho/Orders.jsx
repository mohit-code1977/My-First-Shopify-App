import React, { useEffect, useState, useRef } from "react";
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
    const [loading, setLoading] = useState(true);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [orderList, setOrderList] = useState(orders || []);
    const [search, setSearch] = useState("");
    const [filterStatus, setFilterStatus] = useState("all");
    const [syncingOrderId, setSyncingOrderId] = useState(null);
    const [syncingPaymentId, setSyncingPaymentId] = useState(null);
    const [syncType, setSyncType] = useState(null);
    const [notification, setNotification] = useState(null);
    const [selectedOrderForPayment, setSelectedOrderForPayment] = useState(null);

    // Bulk Selection State
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkSyncing, setBulkSyncing] = useState(false);
    const [bulkResultsModal, setBulkResultsModal] = useState(null);

    const headerCheckboxRef = useRef(null);

    const loadData = async () => {
        setLoading(true);
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
            setLoading(false);
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
                await loadData();
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
                await loadData();
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
                await loadData();
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
                pillStyle: {
                    bgColor: "#eafbdf",
                    textColor: "#108043",
                    borderColor: "#b7eb8f",
                },
            };
        }

        if (paidSum > 0 && paidSum < total) {
            return {
                status: "partial",
                label: `$${paidSum.toFixed(2)} / $${total.toFixed(2)} Partial`,
                pillStyle: {
                    bgColor: "#fff8e6",
                    textColor: "#b78103",
                    borderColor: "#ffe58f",
                },
            };
        }

        if (hasFailed) {
            return {
                status: "failed",
                label: "Sync Failed",
                pillStyle: {
                    bgColor: "#fbeae8",
                    textColor: "#d72c0d",
                    borderColor: "#f3baba",
                },
            };
        }

        if (order.financial_status === "refunded" || payments.some((p) => p.status === "refunded")) {
            return {
                status: "refunded",
                label: "Refunded",
                pillStyle: {
                    bgColor: "#f1f2f4",
                    textColor: "#616a75",
                    borderColor: "#c9cccf",
                },
            };
        }

        if (order.financial_status === "paid") {
            return {
                status: "paid_pending_sync",
                label: hasPending ? "Sync Pending" : "Paid (Shopify)",
                pillStyle: {
                    bgColor: "#e8f4fe",
                    textColor: "#005bd3",
                    borderColor: "#b4d5fe",
                },
            };
        }

        return {
            status: "pending",
            label: "Pending",
            pillStyle: {
                bgColor: "#fff8e6",
                textColor: "#b78103",
                borderColor: "#ffe58f",
            },
        };
    };

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
        if (filterStatus === "invoiced") return hasInvoice;
        if (filterStatus === "pending") return !hasInvoice;

        const paySummary = getPaymentSummary(o);
        if (filterStatus === "paid") return paySummary.status === "paid";
        if (filterStatus === "partial") return paySummary.status === "partial";
        if (filterStatus === "failed") return paySummary.status === "failed";

        return true;
    });

    const visibleIds = filteredOrders.map((o) => o.id);
    const isAllSelected =
        visibleIds.length > 0 &&
        visibleIds.every((id) => selectedIds.includes(id));
    const isIndeterminate =
        selectedIds.length > 0 && !isAllSelected;

    useEffect(() => {
        if (headerCheckboxRef.current) {
            headerCheckboxRef.current.indeterminate = isIndeterminate;
        }
    }, [isIndeterminate]);

    const handleToggleSelectAll = () => {
        if (isAllSelected) {
            setSelectedIds((prev) =>
                prev.filter((id) => !visibleIds.includes(id))
            );
        } else {
            const combined = Array.from(
                new Set([...selectedIds, ...visibleIds])
            );
            setSelectedIds(combined);
        }
    };

    const handleToggleSelectRow = (id) => {
        setSelectedIds((prev) =>
            prev.includes(id)
                ? prev.filter((item) => item !== id)
                : [...prev, id]
        );
    };

    const handleBulkSync = async (type = "order") => {
        if (selectedIds.length === 0) return;

        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setBulkSyncing(true);
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
                    order_ids: selectedIds,
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
                await loadData();
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
        }
    };

    return (
        <ZohoLayout
            title="Orders & Invoices | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="orders"
        >
            <div
                style={{
                    display: "flex",
                    flexDirection: "column",
                    gap: "20px",
                }}
            >
                {/* NOTIFICATION */}
                {notification && (
                    <div
                        style={{
                            padding: "12px 16px",
                            borderRadius: "8px",
                            fontSize: "14px",
                            fontWeight: 500,
                            backgroundColor:
                                notification.type === "success"
                                    ? "#eafbdf"
                                    : "#fbeae8",
                            color:
                                notification.type === "success"
                                    ? "#108043"
                                    : "#d72c0d",
                            border:
                                notification.type === "success"
                                    ? "1px solid #b7eb8f"
                                    : "1px solid #f3baba",
                            display: "flex",
                            justifyContent: "space-between",
                            alignItems: "center",
                        }}
                    >
                        <span>{notification.message}</span>
                        <button
                            type="button"
                            onClick={() => setNotification(null)}
                            style={{
                                background: "none",
                                border: "none",
                                cursor: "pointer",
                                fontSize: "16px",
                            }}
                        >
                            ×
                        </button>
                    </div>
                )}

                {/* HEADER */}
                <div
                    style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                    }}
                >
                    <div>
                        <h1
                            style={{
                                fontSize: "24px",
                                fontWeight: 700,
                                color: "#1a1d20",
                                margin: 0,
                            }}
                        >
                            Orders &amp; Invoices
                        </h1>
                        <p
                            style={{
                                fontSize: "14px",
                                color: "#616a75",
                                margin: "4px 0 0 0",
                            }}
                        >
                            Manage Shopify orders, invoices, and payment synchronization to Zoho Books.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={loadData}
                        disabled={loading}
                        style={{
                            padding: "8px 16px",
                            borderRadius: "6px",
                            border: "1px solid #c9cccf",
                            backgroundColor: "#ffffff",
                            fontSize: "13px",
                            fontWeight: 600,
                            color: "#202223",
                            cursor: loading ? "wait" : "pointer",
                        }}
                    >
                        {loading ? "Refreshing..." : "↻ Refresh"}
                    </button>
                </div>

                {/* SEARCH & FILTERS BAR */}
                <div
                    style={{
                        backgroundColor: "#ffffff",
                        borderRadius: "10px",
                        padding: "16px",
                        border: "1px solid #e1e3e5",
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        gap: "16px",
                        flexWrap: "wrap",
                    }}
                >
                    <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
                        {[
                            { key: "all", label: `All (${orderList.length})` },
                            {
                                key: "invoiced",
                                label: `Invoiced (${orderList.filter((o) => o.invoice?.zoho_invoice_id).length})`,
                            },
                            {
                                key: "pending",
                                label: `Pending Invoice (${orderList.filter((o) => !o.invoice?.zoho_invoice_id).length})`,
                            },
                        ].map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setFilterStatus(tab.key)}
                                style={{
                                    padding: "6px 14px",
                                    borderRadius: "20px",
                                    border: "none",
                                    fontSize: "13px",
                                    fontWeight:
                                        filterStatus === tab.key ? 600 : 500,
                                    backgroundColor:
                                        filterStatus === tab.key
                                            ? "#202223"
                                            : "#f1f2f4",
                                    color:
                                        filterStatus === tab.key
                                            ? "#ffffff"
                                            : "#616a75",
                                    cursor: "pointer",
                                }}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    <input
                        type="text"
                        placeholder="Search order #, customer..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        style={{
                            padding: "8px 14px",
                            borderRadius: "6px",
                            border: "1px solid #c9cccf",
                            fontSize: "13px",
                            width: "280px",
                            boxSizing: "border-box",
                        }}
                    />
                </div>

                {/* BULK ACTION TOOLBAR */}
                {selectedIds.length > 0 && (
                    <div
                        style={{
                            backgroundColor: "#002040",
                            color: "#ffffff",
                            borderRadius: "8px",
                            padding: "12px 20px",
                            display: "flex",
                            justifyContent: "space-between",
                            alignItems: "center",
                            boxShadow: "0 2px 8px rgba(0,0,0,0.15)",
                            flexWrap: "wrap",
                            gap: "12px",
                        }}
                    >
                        <div style={{ display: "flex", alignItems: "center", gap: "12px" }}>
                            <span style={{ fontSize: "14px", fontWeight: 600 }}>
                                ✓ {selectedIds.length} order(s) selected
                            </span>
                            <button
                                type="button"
                                onClick={() => setSelectedIds([])}
                                style={{
                                    background: "none",
                                    border: "none",
                                    color: "#99ccee",
                                    fontSize: "13px",
                                    cursor: "pointer",
                                    textDecoration: "underline",
                                }}
                            >
                                Deselect all
                            </button>
                        </div>

                        <div style={{ display: "flex", gap: "10px", flexWrap: "wrap" }}>
                            <button
                                type="button"
                                onClick={() => handleBulkSync("order")}
                                disabled={bulkSyncing}
                                style={{
                                    padding: "8px 14px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#008060",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: bulkSyncing ? "wait" : "pointer",
                                }}
                            >
                                {bulkSyncing ? "Syncing..." : "Sync Sales Orders"}
                            </button>

                            <button
                                type="button"
                                onClick={() => handleBulkSync("invoice")}
                                disabled={bulkSyncing}
                                style={{
                                    padding: "8px 14px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#005bd3",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: bulkSyncing ? "wait" : "pointer",
                                }}
                            >
                                {bulkSyncing ? "Syncing..." : "Sync Invoices"}
                            </button>

                            <button
                                type="button"
                                onClick={() => handleBulkSync("payment")}
                                disabled={bulkSyncing}
                                style={{
                                    padding: "8px 14px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#5c6ac4",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: bulkSyncing ? "wait" : "pointer",
                                }}
                            >
                                {bulkSyncing ? "Syncing..." : "Sync Payments"}
                            </button>
                        </div>
                    </div>
                )}

                {/* ORDERS TABLE */}
                <div
                    style={{
                        backgroundColor: "#ffffff",
                        borderRadius: "10px",
                        border: "1px solid #e1e3e5",
                        overflow: "hidden",
                    }}
                >
                    <table
                        style={{
                            width: "100%",
                            borderCollapse: "collapse",
                            fontSize: "13px",
                        }}
                    >
                        <thead>
                            <tr
                                style={{
                                    backgroundColor: "#f8f9fa",
                                    borderBottom: "1px solid #e1e3e5",
                                    textAlign: "left",
                                    color: "#616a75",
                                }}
                            >
                                <th style={{ padding: "12px 16px", width: "40px" }}>
                                    <input
                                        type="checkbox"
                                        ref={headerCheckboxRef}
                                        checked={isAllSelected}
                                        onChange={handleToggleSelectAll}
                                        style={{ cursor: "pointer", width: "16px", height: "16px" }}
                                    />
                                </th>
                                <th style={{ padding: "12px 16px" }}>ORDER #</th>
                                <th style={{ padding: "12px 16px" }}>CUSTOMER</th>
                                <th style={{ padding: "12px 16px" }}>DATE</th>
                                <th style={{ padding: "12px 16px" }}>TOTAL</th>
                                <th style={{ padding: "12px 16px" }}>ZOHO SALES ORDER</th>
                                <th style={{ padding: "12px 16px" }}>ZOHO INVOICE</th>
                                <th style={{ padding: "12px 16px" }}>INVOICE STATUS</th>
                                <th style={{ padding: "12px 16px" }}>PAYMENT</th>
                                <th
                                    style={{
                                        padding: "12px 16px",
                                        textAlign: "right",
                                    }}
                                >
                                    ACTIONS
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td
                                        colSpan={10}
                                        style={{
                                            textAlign: "center",
                                            padding: "40px",
                                            color: "#616a75",
                                        }}
                                    >
                                        Loading orders...
                                    </td>
                                </tr>
                            ) : filteredOrders.length > 0 ? (
                                filteredOrders.map((o) => {
                                    const isSyncing = syncingOrderId === o.id;
                                    const isSelected = selectedIds.includes(o.id);
                                    const hasInvoice = !!o.invoice?.zoho_invoice_id;
                                    const paySummary = getPaymentSummary(o);

                                    return (
                                        <tr
                                            key={o.id}
                                            style={{
                                                borderBottom: "1px solid #f1f2f4",
                                                backgroundColor: isSelected ? "#f4f6f8" : "transparent",
                                            }}
                                        >
                                            <td style={{ padding: "12px 16px", width: "40px" }}>
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => handleToggleSelectRow(o.id)}
                                                    style={{ cursor: "pointer", width: "16px", height: "16px" }}
                                                />
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontWeight: 700,
                                                    color: "#005bd3",
                                                }}
                                            >
                                                {o.name || (o.order_number ? `#${o.order_number}` : `#${o.shopify_order_id}`)}
                                            </td>
                                            <td style={{ padding: "12px 16px" }}>
                                                {o.customer ? (
                                                    <div>
                                                        <div
                                                            style={{
                                                                fontWeight: 600,
                                                                color: "#1a1d20",
                                                            }}
                                                        >
                                                            {o.customer.first_name} {o.customer.last_name}
                                                        </div>
                                                        <div
                                                            style={{
                                                                fontSize: "12px",
                                                                color: "#616a75",
                                                            }}
                                                        >
                                                            {o.customer.email}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span style={{ color: "#616a75" }}>
                                                        Guest Customer
                                                    </span>
                                                )}
                                            </td>
                                            <td style={{ padding: "12px 16px", color: "#616a75" }}>
                                                {o.created_at
                                                    ? new Date(o.created_at).toLocaleDateString()
                                                    : "—"}
                                            </td>
                                            <td style={{ padding: "12px 16px", fontWeight: 600 }}>
                                                ${parseFloat(o.total_price || 0).toFixed(2)}{" "}
                                                {o.currency || "USD"}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontFamily: "monospace",
                                                    color: "#202223",
                                                }}
                                            >
                                                {o.zoho_salesorder_id ? (
                                                    `SO-${o.zoho_salesorder_id}`
                                                ) : (
                                                    <span style={{ color: "#8c9196" }}>
                                                        Not Created
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontFamily: "monospace",
                                                    color: "#202223",
                                                }}
                                            >
                                                {o.invoice?.zoho_invoice_id ? (
                                                    `INV-${o.invoice.zoho_invoice_id}`
                                                ) : (
                                                    <span style={{ color: "#8c9196" }}>
                                                        Not Created
                                                    </span>
                                                )}
                                            </td>
                                            <td style={{ padding: "12px 16px" }}>
                                                <span
                                                    style={{
                                                        padding: "3px 10px",
                                                        borderRadius: "12px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        backgroundColor: hasInvoice
                                                            ? "#eafbdf"
                                                            : "#fff8e6",
                                                        color: hasInvoice
                                                            ? "#108043"
                                                            : "#b78103",
                                                        border: hasInvoice
                                                            ? "1px solid #b7eb8f"
                                                            : "1px solid #ffe58f",
                                                    }}
                                                >
                                                    {hasInvoice ? "Invoiced" : "Pending Invoice"}
                                                </span>
                                            </td>
                                            {/* PAYMENT STATUS COLUMN */}
                                            <td style={{ padding: "12px 16px" }}>
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedOrderForPayment(o)}
                                                    style={{
                                                        padding: "4px 10px",
                                                        borderRadius: "12px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        border: `1px solid ${paySummary.pillStyle.borderColor}`,
                                                        backgroundColor: paySummary.pillStyle.bgColor,
                                                        color: paySummary.pillStyle.textColor,
                                                        cursor: "pointer",
                                                        display: "inline-flex",
                                                        alignItems: "center",
                                                        gap: "4px",
                                                    }}
                                                    title="Click to view payment details"
                                                >
                                                    <span>{paySummary.label}</span>
                                                    <span style={{ fontSize: "10px", opacity: 0.7 }}>ℹ</span>
                                                </button>
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    textAlign: "right",
                                                }}
                                            >
                                                <div
                                                    style={{
                                                        display: "flex",
                                                        gap: "6px",
                                                        justifyContent: "flex-end",
                                                    }}
                                                >
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSyncOrder(o.id)}
                                                        disabled={isSyncing || bulkSyncing}
                                                        style={{
                                                            padding: "5px 10px",
                                                            borderRadius: "6px",
                                                            border: "1px solid #c9cccf",
                                                            backgroundColor: "#ffffff",
                                                            fontSize: "12px",
                                                            fontWeight: 600,
                                                            color: "#202223",
                                                            cursor: isSyncing ? "wait" : "pointer",
                                                        }}
                                                    >
                                                        {isSyncing && syncType === "order"
                                                            ? "Syncing..."
                                                            : "Sync Order"}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSyncInvoice(o.id)}
                                                        disabled={isSyncing || bulkSyncing}
                                                        style={{
                                                            padding: "5px 10px",
                                                            borderRadius: "6px",
                                                            border: "1px solid #005bd3",
                                                            backgroundColor: "#005bd3",
                                                            fontSize: "12px",
                                                            fontWeight: 600,
                                                            color: "#ffffff",
                                                            cursor: isSyncing ? "wait" : "pointer",
                                                        }}
                                                    >
                                                        {isSyncing && syncType === "invoice"
                                                            ? "Syncing..."
                                                            : "Sync Invoice"}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td
                                        colSpan={10}
                                        style={{
                                            textAlign: "center",
                                            padding: "40px",
                                            color: "#616a75",
                                        }}
                                    >
                                        No orders found matching your search.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* BULK RESULTS MODAL */}
            {bulkResultsModal && (
                <div
                    style={{
                        position: "fixed",
                        top: 0,
                        left: 0,
                        right: 0,
                        bottom: 0,
                        backgroundColor: "rgba(0,0,0,0.5)",
                        display: "flex",
                        justifyContent: "center",
                        alignItems: "center",
                        zIndex: 10000,
                    }}
                >
                    <div
                        style={{
                            backgroundColor: "#ffffff",
                            borderRadius: "12px",
                            padding: "24px",
                            width: "90%",
                            maxWidth: "600px",
                            maxHeight: "80vh",
                            overflowY: "auto",
                            boxShadow: "0 4px 20px rgba(0,0,0,0.2)",
                        }}
                    >
                        <h2 style={{ fontSize: "18px", fontWeight: 700, margin: "0 0 12px 0" }}>
                            Bulk Synchronization Results ({bulkResultsModal.syncType?.toUpperCase() || "ORDER"})
                        </h2>

                        <div style={{ display: "flex", gap: "10px", marginBottom: "16px" }}>
                            <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#e4e5e7", color: "#202223" }}>
                                Total: {bulkResultsModal.summary?.total || 0}
                            </span>
                            <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#eafbdf", color: "#108043" }}>
                                Synced: {bulkResultsModal.summary?.synced || 0}
                            </span>
                            <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#fbeae8", color: "#d72c0d" }}>
                                Failed: {bulkResultsModal.summary?.failed || 0}
                            </span>
                            {bulkResultsModal.summary?.skipped > 0 && (
                                <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#fff8e6", color: "#b78103" }}>
                                    Skipped: {bulkResultsModal.summary?.skipped}
                                </span>
                            )}
                        </div>

                        <div style={{ border: "1px solid #e1e3e5", borderRadius: "8px", overflow: "hidden", marginBottom: "20px" }}>
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
                                                <span
                                                    style={{
                                                        padding: "2px 8px",
                                                        borderRadius: "10px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        backgroundColor:
                                                            res.status === "success"
                                                                ? "#eafbdf"
                                                                : res.status === "skipped"
                                                                ? "#fff8e6"
                                                                : "#fbeae8",
                                                        color:
                                                            res.status === "success"
                                                                ? "#108043"
                                                                : res.status === "skipped"
                                                                ? "#b78103"
                                                                : "#d72c0d",
                                                    }}
                                                >
                                                    {res.status}
                                                </span>
                                            </td>
                                            <td style={{ padding: "10px 14px", color: "#616a75" }}>
                                                {res.message}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div style={{ textAlign: "right" }}>
                            <button
                                type="button"
                                onClick={() => {
                                    setBulkResultsModal(null);
                                    setSelectedIds([]);
                                }}
                                style={{
                                    padding: "8px 18px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#202223",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: "pointer",
                                }}
                            >
                                Close &amp; Refresh
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* PAYMENT DETAILS MODAL */}
            {selectedOrderForPayment && (
                <div
                    style={{
                        position: "fixed",
                        top: 0,
                        left: 0,
                        right: 0,
                        bottom: 0,
                        backgroundColor: "rgba(0, 0, 0, 0.5)",
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "center",
                        zIndex: 9999,
                        padding: "20px",
                    }}
                    onClick={() => setSelectedOrderForPayment(null)}
                >
                    <div
                        style={{
                            backgroundColor: "#ffffff",
                            borderRadius: "12px",
                            maxWidth: "600px",
                            width: "100%",
                            maxHeight: "85vh",
                            overflowY: "auto",
                            boxShadow: "0 10px 25px rgba(0,0,0,0.15)",
                            padding: "24px",
                        }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* MODAL HEADER */}
                        <div
                            style={{
                                display: "flex",
                                justifyContent: "space-between",
                                alignItems: "flex-start",
                                borderBottom: "1px solid #e1e3e5",
                                paddingBottom: "16px",
                                marginBottom: "16px",
                            }}
                        >
                            <div>
                                <h2
                                    style={{
                                        fontSize: "18px",
                                        fontWeight: 700,
                                        color: "#1a1d20",
                                        margin: 0,
                                    }}
                                >
                                    Payment Details — {selectedOrderForPayment.name || `#${selectedOrderForPayment.order_number}`}
                                </h2>
                                <p
                                    style={{
                                        fontSize: "13px",
                                        color: "#616a75",
                                        margin: "4px 0 0 0",
                                    }}
                                >
                                    Associated Invoice:{" "}
                                    <strong style={{ color: "#005bd3", fontFamily: "monospace" }}>
                                        {selectedOrderForPayment.invoice?.zoho_invoice_id
                                            ? `INV-${selectedOrderForPayment.invoice.zoho_invoice_id}`
                                            : "Pending Invoice"}
                                    </strong>
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedOrderForPayment(null)}
                                style={{
                                    background: "none",
                                    border: "none",
                                    fontSize: "20px",
                                    cursor: "pointer",
                                    color: "#616a75",
                                }}
                            >
                                ×
                            </button>
                        </div>

                        {/* SUMMARY CARDS */}
                        <div
                            style={{
                                display: "grid",
                                gridTemplateColumns: "1fr 1fr",
                                gap: "12px",
                                marginBottom: "20px",
                                fontSize: "13px",
                            }}
                        >
                            <div style={{ backgroundColor: "#f8f9fa", padding: "12px", borderRadius: "8px" }}>
                                <div style={{ color: "#616a75", fontSize: "12px" }}>Order Total</div>
                                <div style={{ fontWeight: 700, color: "#1a1d20", marginTop: "2px", fontSize: "15px" }}>
                                    ${parseFloat(selectedOrderForPayment.total_price || 0).toFixed(2)} {selectedOrderForPayment.currency || "USD"}
                                </div>
                            </div>
                            <div style={{ backgroundColor: "#f8f9fa", padding: "12px", borderRadius: "8px" }}>
                                <div style={{ color: "#616a75", fontSize: "12px" }}>Financial Status</div>
                                <div style={{ fontWeight: 600, color: "#1a1d20", marginTop: "2px", textTransform: "capitalize" }}>
                                    {selectedOrderForPayment.financial_status || "pending"}
                                </div>
                            </div>
                        </div>

                        {/* PAYMENTS LIST */}
                        <h3 style={{ fontSize: "14px", fontWeight: 600, color: "#1a1d20", marginBottom: "12px" }}>
                            Payment Transactions ({selectedOrderForPayment.payments ? selectedOrderForPayment.payments.length : 0})
                        </h3>

                        {!selectedOrderForPayment.payments || selectedOrderForPayment.payments.length === 0 ? (
                            <div
                                style={{
                                    fontSize: "13px",
                                    color: "#616a75",
                                    fontStyle: "italic",
                                    padding: "16px",
                                    backgroundColor: "#fafafa",
                                    borderRadius: "8px",
                                    border: "1px dashed #c9cccf",
                                    textAlign: "center",
                                }}
                            >
                                No payment transaction recorded locally for this order.
                                {selectedOrderForPayment.invoice && (
                                    <div style={{ marginTop: "8px" }}>
                                        <button
                                            type="button"
                                            onClick={() => handleSyncPayment(null, selectedOrderForPayment.id)}
                                            disabled={syncingPaymentId === selectedOrderForPayment.id}
                                            style={{
                                                padding: "6px 14px",
                                                borderRadius: "6px",
                                                border: "1px solid #005bd3",
                                                backgroundColor: "#005bd3",
                                                color: "#ffffff",
                                                fontSize: "12px",
                                                fontWeight: 600,
                                                cursor: syncingPaymentId === selectedOrderForPayment.id ? "wait" : "pointer",
                                            }}
                                        >
                                            {syncingPaymentId === selectedOrderForPayment.id ? "Creating & Syncing Payment..." : "Record & Sync Payment to Zoho"}
                                        </button>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div style={{ display: "flex", flexDirection: "column", gap: "12px" }}>
                                {selectedOrderForPayment.payments.map((p) => {
                                    const isPaymentSyncing = syncingPaymentId === p.id || syncingPaymentId === selectedOrderForPayment.id;
                                    const isSynced = p.sync_status === "synced" || !!p.zoho_payment_id;
                                    const isFailed = p.sync_status === "failed";

                                    return (
                                        <div
                                            key={p.id}
                                            style={{
                                                backgroundColor: "#ffffff",
                                                border: "1px solid #e1e3e5",
                                                borderRadius: "8px",
                                                padding: "14px",
                                            }}
                                        >
                                            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                                                <div>
                                                    <span style={{ fontWeight: 700, fontSize: "14px", color: "#1a1d20" }}>
                                                        ${parseFloat(p.amount || 0).toFixed(2)} {p.currency || "USD"}
                                                    </span>
                                                </div>
                                                <span
                                                    style={{
                                                        padding: "3px 10px",
                                                        borderRadius: "12px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        backgroundColor: isSynced ? "#eafbdf" : isFailed ? "#fbeae8" : "#fff8e6",
                                                        color: isSynced ? "#108043" : isFailed ? "#d72c0d" : "#b78103",
                                                        border: `1px solid ${isSynced ? "#b7eb8f" : isFailed ? "#f3baba" : "#ffe58f"}`,
                                                    }}
                                                >
                                                    Sync: {p.sync_status ? p.sync_status.toUpperCase() : "PENDING"}
                                                </span>
                                            </div>

                                            <div
                                                style={{
                                                    display: "grid",
                                                    gridTemplateColumns: "1fr 1fr",
                                                    gap: "8px 16px",
                                                    fontSize: "12px",
                                                    color: "#616a75",
                                                    marginTop: "8px",
                                                }}
                                            >
                                                <div>
                                                    <strong>Payment Method:</strong> {p.payment_method || "shopify_payments"}
                                                </div>
                                                <div>
                                                    <strong>Payment Date:</strong> {p.payment_date ? new Date(p.payment_date).toLocaleString() : "—"}
                                                </div>
                                                <div>
                                                    <strong>Shopify Txn ID:</strong>{" "}
                                                    <span style={{ fontFamily: "monospace" }}>{p.shopify_transaction_id || p.payment_reference || "—"}</span>
                                                </div>
                                                <div>
                                                    <strong>Zoho Payment ID:</strong>{" "}
                                                    <span style={{ fontFamily: "monospace", color: p.zoho_payment_id ? "#005bd3" : "#8c9196" }}>
                                                        {p.zoho_payment_id || "Not Synced"}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* ERROR MESSAGE IF FAILED */}
                                            {isFailed && p.error_message && (
                                                <div
                                                    style={{
                                                        marginTop: "12px",
                                                        padding: "10px 12px",
                                                        borderRadius: "6px",
                                                        backgroundColor: "#fbeae8",
                                                        border: "1px solid #f3baba",
                                                        color: "#d72c0d",
                                                        fontSize: "12px",
                                                    }}
                                                >
                                                    <strong>Error: </strong> {p.error_message}
                                                </div>
                                            )}

                                            {/* RETRY / SYNC ACTION */}
                                            {(!isSynced || isFailed) && (
                                                <div style={{ marginTop: "12px", textAlign: "right" }}>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSyncPayment(p.id, selectedOrderForPayment.id)}
                                                        disabled={isPaymentSyncing || bulkSyncing}
                                                        style={{
                                                            padding: "6px 14px",
                                                            borderRadius: "6px",
                                                            border: "1px solid #005bd3",
                                                            backgroundColor: "#005bd3",
                                                            color: "#ffffff",
                                                            fontSize: "12px",
                                                            fontWeight: 600,
                                                            cursor: isPaymentSyncing ? "wait" : "pointer",
                                                        }}
                                                    >
                                                        {isPaymentSyncing ? "Syncing to Zoho..." : "Retry Payment"}
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {/* MODAL FOOTER */}
                        <div
                            style={{
                                marginTop: "20px",
                                borderTop: "1px solid #e1e3e5",
                                paddingTop: "16px",
                                display: "flex",
                                justifyContent: "flex-end",
                            }}
                        >
                            <button
                                type="button"
                                onClick={() => setSelectedOrderForPayment(null)}
                                style={{
                                    padding: "8px 16px",
                                    borderRadius: "6px",
                                    border: "1px solid #c9cccf",
                                    backgroundColor: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    color: "#202223",
                                    cursor: "pointer",
                                }}
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ZohoLayout>
    );
}
