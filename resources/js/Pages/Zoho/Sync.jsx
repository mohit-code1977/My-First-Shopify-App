import React, { useEffect, useState } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

export default function Sync({
    shop,
    histories,
    zohoConnected = false,
    filters = {},
    host = "",
}) {
    const [shopData, setShopData] = useState(shop || {});
    const [historiesState, setHistoriesState] = useState(
        histories || { data: [], total: 0, current_page: 1, last_page: 1 },
    );
    const [summaryMetrics, setSummaryMetrics] = useState({
        connected_products: 0,
        total: 0,
        success: 0,
        failed: 0,
        pending: 0,
        reconciled: 0,
    });
    const [zohoConn, setZohoConn] = useState(zohoConnected);
    const [loading, setLoading] = useState(false);
    const [selectedHistory, setSelectedHistory] = useState(null);
    const [showModal, setShowModal] = useState(false);

    // Filters state
    const [search, setSearch] = useState(filters.search || "");
    const [statusFilter, setStatusFilter] = useState(filters.status || "all");
    const [entityFilter, setEntityFilter] = useState(filters.entity || "all");
    const [triggerFilter, setTriggerFilter] = useState(filters.trigger || "all");

    const historyData = historiesState?.data || [];

    const loadData = async (
        page = historiesState?.current_page || 1,
        searchQuery = search,
        statusVal = statusFilter,
        entityVal = entityFilter,
        triggerVal = triggerFilter,
    ) => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const params = new URLSearchParams({
                page: String(page),
                search: searchQuery,
                status: statusVal,
                entity: entityVal,
                trigger: triggerVal,
            });
            const response = await fetch(
                `/api/zoho/sync/history?${params.toString()}`,
                { headers },
            );
            const data = await response.json();
            if (response.ok && data.success) {
                if (data.shop) setShopData(data.shop);
                if (data.histories) setHistoriesState(data.histories);
                if (data.summary) setSummaryMetrics(data.summary);
                if (typeof data.zohoConnected === "boolean") {
                    setZohoConn(data.zohoConnected);
                }
            }
        } catch (error) {
            console.error("Failed to load sync monitoring data:", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        const timeout = setTimeout(() => {
            loadData(1, search, statusFilter, entityFilter, triggerFilter);
        }, 300);

        return () => clearTimeout(timeout);
    }, [search, statusFilter, entityFilter, triggerFilter]);

    /*
    |--------------------------------------------------------------------------
    | Data Formatting Helpers
    |--------------------------------------------------------------------------
    */

    const formatLabel = (value) => {
        if (!value) return "—";
        const normalized = String(value).replace(/[_-]+/g, " ").trim();
        return normalized.replace(/\b\w/g, (char) => char.toUpperCase());
    };

    const formatDate = (value) => {
        if (!value) return { date: "—", time: "" };
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return { date: "—", time: "" };
        return {
            date: date.toLocaleDateString(),
            time: date.toLocaleTimeString([], {
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit",
            }),
        };
    };

    const getRecordTitle = (history) => {
        if (history?.metadata?.product_name || history?.metadata?.product_title) {
            return history.metadata.product_name || history.metadata.product_title;
        }
        if (history?.metadata?.order_number) {
            return `Order ${history.metadata.order_number}`;
        }
        if (history?.metadata?.invoice_number) {
            return `Invoice ${history.metadata.invoice_number}`;
        }
        if (history?.metadata?.payment_reference) {
            return `Payment #${history.metadata.payment_reference}`;
        }
        if (history?.metadata?.customer_name) {
            return history.metadata.customer_name;
        }
        if (history?.payment) {
            return `Payment #${history.payment.payment_reference || history.payment.shopify_transaction_id || history.payment.id}`;
        }
        if (history?.invoice) {
            return `Invoice #${history.invoice.invoice_number || history.invoice.zoho_invoice_id || history.invoice.id}`;
        }
        if (history?.order) {
            return `Order ${history.order.name || `#${history.order.order_number}`}`;
        }
        if (history?.product_variant?.product?.title) {
            return history.product_variant.product.title;
        }
        return history?.entity ? formatLabel(history.entity) : "Sync Event";
    };

    const getRecordSubtitle = (history) => {
        const meta = history?.metadata || {};
        const parts = [];
        if (meta.variant_name || meta.variant_title) parts.push(meta.variant_name || meta.variant_title);
        if (meta.sku) parts.push(`SKU: ${meta.sku}`);
        if (meta.amount !== undefined && meta.amount !== null) {
            const curr = meta.currency || "USD";
            parts.push(`${curr} ${parseFloat(meta.amount).toFixed(2)}`);
        }
        if (parts.length > 0) return parts.join(" • ");

        if (history?.product_variant) {
            return `Variant: ${history.product_variant.title || "Default"} • SKU: ${history.product_variant.sku || "N/A"}`;
        }
        if (history?.order?.customer) {
            const c = history.order.customer;
            return `Customer: ${c.first_name || ""} ${c.last_name || ""}`.trim();
        }
        return history?.shopify_id ? `ID: ${history.shopify_id}` : "System Record";
    };

    const getStatus = (history) => {
        const status = String(history?.status || "pending").toLowerCase();
        if (["success", "successful", "synced"].includes(status)) return "success";
        if (["failed", "error"].includes(status)) return "failed";
        if (["reconciled", "reconcile"].includes(status)) return "reconciled";
        if (["skipped", "skip"].includes(status)) return "skipped";
        return "pending";
    };

    const getTriggerLabel = (history) => {
        if (history?.trigger_label) return history.trigger_label;

        const trig = ucfirst(history?.trigger || "manual");
        if (history?.trigger_subtype) {
            const sub = history.trigger_subtype.replace(/_/g, " ");
            return `${trig} → ${ucwords(sub)}`;
        }
        return trig;
    };

    function ucfirst(str) {
        if (!str) return "";
        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    }

    function ucwords(str) {
        if (!str) return "";
        return str.replace(/\b\w/g, (c) => c.toUpperCase());
    }

    const getStatusPillStyle = (status) => {
        switch (status) {
            case "success":
                return { backgroundColor: "#eafbdf", color: "#108043", border: "1px solid #b7eb8f" };
            case "failed":
                return { backgroundColor: "#fbeae8", color: "#d72c0d", border: "1px solid #f3baba" };
            case "reconciled":
                return { backgroundColor: "#e8f4fe", color: "#005bd3", border: "1px solid #b6d8fe" };
            case "skipped":
                return { backgroundColor: "#f1f2f4", color: "#616a75", border: "1px solid #c9cccf" };
            default:
                return { backgroundColor: "#fff8e6", color: "#b78103", border: "1px solid #ffe58f" };
        }
    };

    const openDetailsModal = (history) => {
        setSelectedHistory(history);
        setShowModal(true);
    };

    return (
        <ZohoLayout
            title="Sync History | Zoho Books Integration"
            shop={shopData}
            zohoConnected={zohoConn}
            host={host}
            activePage="sync"
        >
            <div style={{ display: "flex", flexDirection: "column", gap: "16px" }}>
                {/* HEADER & TOP ACTIONS */}
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                    <div>
                        <h1 style={{ fontSize: "24px", fontWeight: 700, color: "#1a1d20", margin: 0 }}>
                            Sync History
                        </h1>
                        <p style={{ fontSize: "14px", color: "#616a75", margin: "4px 0 0 0" }}>
                            Track Shopify ↔ Zoho synchronization activity.
                        </p>
                    </div>

                    <div>
                        <button
                            type="button"
                            onClick={() => loadData()}
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
                </div>

                {/* FULL-WIDTH COMPACT SUMMARY BAR */}
                <div
                    style={{
                        backgroundColor: "#ffffff",
                        borderRadius: "8px",
                        border: "1px solid #e1e3e5",
                        display: "grid",
                        gridTemplateColumns: "repeat(4, 1fr)",
                        alignItems: "center",
                        width: "100%",
                        boxSizing: "border-box",
                        overflow: "hidden",
                    }}
                >
                    {/* Connected Products */}
                    <div
                        style={{
                            padding: "14px 20px",
                            borderRight: "1px solid #e1e3e5",
                            display: "flex",
                            alignItems: "baseline",
                            gap: "10px",
                        }}
                    >
                        <span style={{ fontSize: "22px", fontWeight: 700, color: "#1a1d20", lineHeight: 1 }}>
                            {summaryMetrics.connected_products || 0}
                        </span>
                        <span style={{ fontSize: "13px", fontWeight: 500, color: "#616a75" }}>
                            Connected Products
                        </span>
                    </div>

                    {/* Sync Events */}
                    <div
                        style={{
                            padding: "14px 20px",
                            borderRight: "1px solid #e1e3e5",
                            display: "flex",
                            alignItems: "baseline",
                            gap: "10px",
                        }}
                    >
                        <span style={{ fontSize: "22px", fontWeight: 700, color: "#1a1d20", lineHeight: 1 }}>
                            {summaryMetrics.total || historyData.length}
                        </span>
                        <span style={{ fontSize: "13px", fontWeight: 500, color: "#616a75" }}>
                            Sync Events
                        </span>
                    </div>

                    {/* Successful */}
                    <div
                        style={{
                            padding: "14px 20px",
                            borderRight: "1px solid #e1e3e5",
                            display: "flex",
                            alignItems: "baseline",
                            gap: "10px",
                        }}
                    >
                        <span style={{ fontSize: "22px", fontWeight: 700, color: "#108043", lineHeight: 1 }}>
                            {summaryMetrics.success || 0}
                        </span>
                        <span style={{ fontSize: "13px", fontWeight: 600, color: "#108043" }}>
                            Successful
                        </span>
                    </div>

                    {/* Failed */}
                    <div
                        style={{
                            padding: "14px 20px",
                            display: "flex",
                            alignItems: "baseline",
                            gap: "10px",
                        }}
                    >
                        <span
                            style={{
                                fontSize: "22px",
                                fontWeight: 700,
                                color: summaryMetrics.failed > 0 ? "#d72c0d" : "#616a75",
                                lineHeight: 1,
                            }}
                        >
                            {summaryMetrics.failed || 0}
                        </span>
                        <span
                            style={{
                                fontSize: "13px",
                                fontWeight: summaryMetrics.failed > 0 ? 600 : 500,
                                color: summaryMetrics.failed > 0 ? "#d72c0d" : "#616a75",
                            }}
                        >
                            Failed
                        </span>
                    </div>
                </div>

                {/* STATUS FILTER TABS & CONTROL DROPDOWNS */}
                <div
                    style={{
                        backgroundColor: "#ffffff",
                        borderRadius: "8px",
                        border: "1px solid #e1e3e5",
                        padding: "12px 16px",
                        display: "flex",
                        justify: "space-between",
                        alignItems: "center",
                        gap: "12px",
                        flexWrap: "wrap",
                    }}
                >
                        {/* Status Tabs */}
                        <div style={{ display: "flex", gap: "6px", flexWrap: "wrap" }}>
                            {[
                                { key: "all", label: "All" },
                                { key: "success", label: "Success" },
                                { key: "failed", label: "Failed" },
                                { key: "pending", label: "Pending" },
                                { key: "reconciled", label: "Reconciled" },
                            ].map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    onClick={() => setStatusFilter(tab.key)}
                                    style={{
                                        padding: "5px 12px",
                                        borderRadius: "20px",
                                        border: "none",
                                        fontSize: "12px",
                                        fontWeight: statusFilter === tab.key ? 600 : 500,
                                        backgroundColor: statusFilter === tab.key ? "#202223" : "#f1f2f4",
                                        color: statusFilter === tab.key ? "#ffffff" : "#616a75",
                                        cursor: "pointer",
                                    }}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>

                        {/* Filter Controls Row */}
                        <div style={{ display: "flex", gap: "10px", alignItems: "center", flexWrap: "wrap" }}>
                            {/* Entity Select */}
                            <select
                                value={entityFilter}
                                onChange={(e) => setEntityFilter(e.target.value)}
                                style={selectStyle}
                            >
                                <option value="all">All Entities</option>
                                <option value="product">Product</option>
                                <option value="product_variant">Product Variant</option>
                                <option value="customer">Customer</option>
                                <option value="order">Order</option>
                                <option value="invoice">Invoice</option>
                                <option value="payment">Payment</option>
                                <option value="refund">Refund / Credit Note</option>
                                <option value="inventory">Inventory</option>
                            </select>

                            {/* Trigger Select */}
                            <select
                                value={triggerFilter}
                                onChange={(e) => setTriggerFilter(e.target.value)}
                                style={selectStyle}
                            >
                                <option value="all">All Triggers</option>
                                <option value="manual">Manual</option>
                                <option value="bulk">Bulk</option>
                                <option value="webhook">Webhook</option>
                                <option value="retry">Retry</option>
                                <option value="automatic">Automatic</option>
                            </select>

                            {/* Search Box */}
                            <input
                                type="text"
                                placeholder="Search entity, order #, SKU..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                style={inputStyle}
                            />
                        </div>
                    </div>

                {/* SYNC HISTORY TABLE */}
                <div style={{ backgroundColor: "#ffffff", borderRadius: "10px", border: "1px solid #e1e3e5", overflow: "hidden" }}>
                    <div style={{ overflowX: "auto" }}>
                        <table style={{ width: "100%", borderCollapse: "collapse", fontSize: "13px", textAlign: "left" }}>
                            <thead>
                                <tr style={{ backgroundColor: "#f8f9fa", borderBottom: "1px solid #e1e3e5", color: "#616a75" }}>
                                    <th style={thStyle}>DATE / TIME</th>
                                    <th style={thStyle}>ENTITY</th>
                                    <th style={thStyle}>RECORD</th>
                                    <th style={thStyle}>ACTION</th>
                                    <th style={thStyle}>TRIGGER</th>
                                    <th style={thStyle}>STATUS</th>
                                    <th style={{ ...thStyle, textAlign: "right" }}>DETAILS</th>
                                </tr>
                            </thead>
                            <tbody>
                                {loading && historyData.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} style={{ textAlign: "center", padding: "30px", color: "#616a75" }}>
                                            Loading sync history events...
                                        </td>
                                    </tr>
                                ) : historyData.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} style={{ textAlign: "center", padding: "36px 16px" }}>
                                            <div style={{ fontSize: "14px", fontWeight: 600, color: "#202223" }}>
                                                No sync history events found
                                            </div>
                                            <div style={{ fontSize: "12px", color: "#616a75", marginTop: "4px" }}>
                                                Sync operations will automatically record here.
                                            </div>
                                        </td>
                                    </tr>
                                ) : (
                                    historyData.map((history) => {
                                        const recordTitle = getRecordTitle(history);
                                        const recordSubtitle = getRecordSubtitle(history);
                                        const status = getStatus(history);
                                        const triggerLabel = getTriggerLabel(history);
                                        const date = formatDate(history.started_at || history.created_at);
                                        const pillStyle = getStatusPillStyle(status);

                                        return (
                                            <tr key={history.id} style={{ borderBottom: "1px solid #f1f2f4" }}>
                                                {/* DATE / TIME */}
                                                <td style={{ padding: "10px 16px", whiteSpace: "nowrap" }}>
                                                    <div style={{ fontWeight: 600, color: "#202223" }}>{date.date}</div>
                                                    <div style={{ fontSize: "11px", color: "#616a75" }}>{date.time}</div>
                                                </td>

                                                {/* ENTITY */}
                                                <td style={{ padding: "10px 16px", fontWeight: 600, color: "#374151" }}>
                                                    {formatLabel(history.entity || history.entity_type)}
                                                </td>

                                                {/* RECORD */}
                                                <td style={{ padding: "10px 16px" }}>
                                                    <div style={{ fontWeight: 600, color: "#1a1d20" }}>
                                                        {recordTitle}
                                                    </div>
                                                    <div style={{ fontSize: "12px", color: "#616a75" }}>
                                                        {recordSubtitle}
                                                    </div>
                                                </td>

                                                {/* ACTION */}
                                                <td style={{ padding: "10px 16px" }}>
                                                    <span style={{ fontSize: "12px", fontWeight: 600, color: "#475569", textTransform: "uppercase" }}>
                                                        {history.action || "SYNC"}
                                                    </span>
                                                </td>

                                                {/* TRIGGER */}
                                                <td style={{ padding: "10px 16px", color: "#374151" }}>
                                                    {triggerLabel}
                                                </td>

                                                {/* STATUS */}
                                                <td style={{ padding: "10px 16px" }}>
                                                    <span
                                                        style={{
                                                            padding: "3px 10px",
                                                            borderRadius: "12px",
                                                            fontSize: "11px",
                                                            fontWeight: 600,
                                                            ...pillStyle,
                                                        }}
                                                    >
                                                        {formatLabel(status)}
                                                    </span>
                                                </td>

                                                {/* DETAILS */}
                                                <td style={{ padding: "10px 16px", textAlign: "right" }}>
                                                    <button
                                                        type="button"
                                                        onClick={() => openDetailsModal(history)}
                                                        style={{
                                                            padding: "4px 10px",
                                                            borderRadius: "4px",
                                                            border: "1px solid #c9cccf",
                                                            backgroundColor: "#ffffff",
                                                            fontSize: "12px",
                                                            fontWeight: 600,
                                                            color: "#005bd3",
                                                            cursor: "pointer",
                                                        }}
                                                    >
                                                        View Details
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* PAGINATION FOOTER */}
                {historiesState.last_page > 1 && (
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "4px 0" }}>
                        <div style={{ fontSize: "12px", color: "#616a75" }}>
                            Page <strong>{historiesState.current_page}</strong> of <strong>{historiesState.last_page}</strong> ({historiesState.total} total events)
                        </div>
                        <div style={{ display: "flex", gap: "8px" }}>
                            <button
                                type="button"
                                disabled={historiesState.current_page <= 1 || loading}
                                onClick={() => loadData(historiesState.current_page - 1)}
                                style={{
                                    padding: "5px 12px",
                                    borderRadius: "6px",
                                    border: "1px solid #c9cccf",
                                    backgroundColor: "#ffffff",
                                    fontSize: "12px",
                                    cursor: historiesState.current_page <= 1 ? "not-allowed" : "pointer",
                                    opacity: historiesState.current_page <= 1 ? 0.5 : 1,
                                }}
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                disabled={historiesState.current_page >= historiesState.last_page || loading}
                                onClick={() => loadData(historiesState.current_page + 1)}
                                style={{
                                    padding: "5px 12px",
                                    borderRadius: "6px",
                                    border: "1px solid #c9cccf",
                                    backgroundColor: "#ffffff",
                                    fontSize: "12px",
                                    cursor: historiesState.current_page >= historiesState.last_page ? "not-allowed" : "pointer",
                                    opacity: historiesState.current_page >= historiesState.last_page ? 0.5 : 1,
                                }}
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}

                {/* DETAILS MODAL */}
                {showModal && selectedHistory && (
                    <div
                        style={{
                            position: "fixed",
                            top: 0,
                            left: 0,
                            right: 0,
                            bottom: 0,
                            backgroundColor: "rgba(0, 0, 0, 0.4)",
                            display: "flex",
                            alignItems: "center",
                            justifyContent: "center",
                            zIndex: 1000,
                        }}
                        onClick={() => setShowModal(false)}
                    >
                        <div
                            style={{
                                width: "560px",
                                maxWidth: "92vw",
                                maxHeight: "85vh",
                                backgroundColor: "#ffffff",
                                borderRadius: "12px",
                                boxShadow: "0 10px 25px rgba(0,0,0,0.2)",
                                overflowY: "auto",
                                padding: "20px 24px",
                                boxSizing: "border-box",
                                display: "flex",
                                flexDirection: "column",
                                gap: "16px",
                            }}
                            onClick={(e) => e.stopPropagation()}
                        >
                            {/* MODAL HEADER */}
                            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", borderBottom: "1px solid #e1e3e5", paddingBottom: "12px" }}>
                                <div>
                                    <h3 style={{ fontSize: "16px", fontWeight: 700, margin: 0, color: "#1a1d20" }}>
                                        Sync Event Details
                                    </h3>
                                    <div style={{ fontSize: "12px", color: "#616a75", marginTop: "2px" }}>
                                        {getRecordTitle(selectedHistory)}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setShowModal(false)}
                                    style={{ background: "none", border: "none", fontSize: "18px", cursor: "pointer", color: "#616a75" }}
                                >
                                    ✕
                                </button>
                            </div>

                            {/* DETAILS GRID */}
                            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "12px", fontSize: "13px" }}>
                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>ENTITY</span>
                                    <div style={detailValueStyle}>{formatLabel(selectedHistory.entity || selectedHistory.entity_type)}</div>
                                </div>

                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>RECORD</span>
                                    <div style={detailValueStyle}>{getRecordTitle(selectedHistory)}</div>
                                </div>

                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>ACTION</span>
                                    <div style={detailValueStyle}>{selectedHistory.action || "SYNC"}</div>
                                </div>

                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>TRIGGER</span>
                                    <div style={detailValueStyle}>{getTriggerLabel(selectedHistory)}</div>
                                </div>

                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>STATUS</span>
                                    <div style={{ marginTop: "2px" }}>
                                        <span style={{ padding: "2px 8px", borderRadius: "10px", fontSize: "11px", fontWeight: 600, ...getStatusPillStyle(getStatus(selectedHistory)) }}>
                                            {formatLabel(getStatus(selectedHistory))}
                                        </span>
                                    </div>
                                </div>

                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>DURATION</span>
                                    <div style={detailValueStyle}>
                                        {selectedHistory.formatted_duration || (selectedHistory.duration_ms ? `${selectedHistory.duration_ms} ms` : "—")}
                                    </div>
                                </div>

                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>SHOPIFY ID</span>
                                    <div style={{ ...detailValueStyle, wordBreak: "break-all" }}>
                                        {selectedHistory.shopify_id || selectedHistory.metadata?.shopify_id || "—"}
                                    </div>
                                </div>

                                <div style={detailBoxStyle}>
                                    <span style={detailLabelStyle}>ZOHO ID</span>
                                    <div style={{ ...detailValueStyle, wordBreak: "break-all" }}>
                                        {selectedHistory.zoho_id || selectedHistory.zoho_item_id || selectedHistory.zoho_invoice_id || selectedHistory.zoho_payment_id || selectedHistory.metadata?.zoho_id || "—"}
                                    </div>
                                </div>
                            </div>

                            {/* ERROR LOG (IF FAILED) */}
                            {getStatus(selectedHistory) === "failed" && (
                                <div style={{ backgroundColor: "#fbeae8", border: "1px solid #f3baba", padding: "12px", borderRadius: "6px" }}>
                                    <div style={{ fontSize: "12px", fontWeight: 700, color: "#d72c0d" }}>
                                        Error Details {selectedHistory.error_code ? `(Code ${selectedHistory.error_code})` : ""}
                                    </div>
                                    <div style={{ fontSize: "12px", color: "#202223", marginTop: "4px", whiteSpace: "pre-wrap" }}>
                                        {selectedHistory.error_message || selectedHistory.message || "Unknown error."}
                                    </div>
                                </div>
                            )}

                            {/* TRANSACTION CHAIN VISUALIZATION */}
                            {(selectedHistory.order || selectedHistory.metadata?.order_number || selectedHistory.metadata?.zoho_sales_order_id) && (
                                <div style={{ backgroundColor: "#f4f6f8", padding: "12px", borderRadius: "6px", border: "1px solid #e1e3e5" }}>
                                    <div style={{ fontSize: "11px", fontWeight: 700, color: "#616a75", textTransform: "uppercase", marginBottom: "8px" }}>
                                        Order Transaction Chain
                                    </div>
                                    <div style={{ display: "flex", alignItems: "center", gap: "6px", flexWrap: "wrap", fontSize: "12px", fontWeight: 600 }}>
                                        <span style={chainBadgeStyle}>
                                            Shopify Order {selectedHistory.metadata?.order_number || (selectedHistory.order?.order_number ? `#${selectedHistory.order.order_number}` : "")}
                                        </span>
                                        <span style={{ color: "#8c9196" }}>→</span>
                                        <span style={chainBadgeStyle}>
                                            Zoho Sales Order
                                        </span>
                                        <span style={{ color: "#8c9196" }}>→</span>
                                        <span style={chainBadgeStyle}>
                                            Zoho Invoice
                                        </span>
                                        <span style={{ color: "#8c9196" }}>→</span>
                                        <span style={chainBadgeStyle}>
                                            Zoho Payment
                                        </span>
                                    </div>
                                </div>
                            )}

                            {(selectedHistory.refund || selectedHistory.metadata?.refund_id || selectedHistory.metadata?.zoho_creditnote_id || selectedHistory.entity === "refund" || selectedHistory.entity === "credit_note") && (
                                <div style={{ backgroundColor: "#f4f6f8", padding: "12px", borderRadius: "6px", border: "1px solid #e1e3e5" }}>
                                    <div style={{ fontSize: "11px", fontWeight: 700, color: "#616a75", textTransform: "uppercase", marginBottom: "8px" }}>
                                        Refund Transaction Chain
                                    </div>
                                    <div style={{ display: "flex", alignItems: "center", gap: "6px", flexWrap: "wrap", fontSize: "12px", fontWeight: 600 }}>
                                        <span style={chainBadgeStyle}>
                                            Shopify Refund
                                        </span>
                                        <span style={{ color: "#8c9196" }}>→</span>
                                        <span style={chainBadgeStyle}>
                                            Zoho Credit Note
                                        </span>
                                    </div>
                                </div>
                            )}

                            {/* METADATA INSPECTOR */}
                            <div>
                                <div style={{ fontSize: "11px", fontWeight: 700, color: "#616a75", textTransform: "uppercase", marginBottom: "6px" }}>
                                    Metadata
                                </div>
                                <pre
                                    style={{
                                        backgroundColor: "#f8f9fa",
                                        color: "#202223",
                                        border: "1px solid #e1e3e5",
                                        padding: "10px",
                                        borderRadius: "6px",
                                        fontSize: "11px",
                                        overflowX: "auto",
                                        margin: 0,
                                        fontFamily: "monospace",
                                    }}
                                >
                                    {JSON.stringify(
                                        selectedHistory.metadata || {
                                            message: selectedHistory.message,
                                        },
                                        null,
                                        2,
                                    )}
                                </pre>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ZohoLayout>
    );
}

const selectStyle = {
    padding: "6px 10px",
    borderRadius: "6px",
    border: "1px solid #c9cccf",
    fontSize: "12px",
    backgroundColor: "#ffffff",
    color: "#202223",
    outline: "none",
};

const inputStyle = {
    padding: "6px 12px",
    borderRadius: "6px",
    border: "1px solid #c9cccf",
    fontSize: "12px",
    backgroundColor: "#ffffff",
    color: "#202223",
    outline: "none",
    width: "200px",
};

const thStyle = {
    padding: "10px 16px",
    fontSize: "11px",
    fontWeight: 700,
    letterSpacing: "0.5px",
};

const detailBoxStyle = {
    backgroundColor: "#f8f9fa",
    border: "1px solid #e1e3e5",
    padding: "8px 10px",
    borderRadius: "6px",
};

const detailLabelStyle = {
    fontSize: "10px",
    fontWeight: 700,
    color: "#616a75",
    textTransform: "uppercase",
};

const detailValueStyle = {
    fontSize: "12px",
    fontWeight: 600,
    color: "#202223",
    marginTop: "2px",
};

const chainBadgeStyle = {
    backgroundColor: "#ffffff",
    padding: "4px 8px",
    borderRadius: "4px",
    border: "1px solid #c9cccf",
    color: "#202223",
};
