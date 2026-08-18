import React, { useEffect, useState, useRef } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

const REFUNDS_DATA_URL = "/api/zoho/refunds";
const SYNC_REFUND_URL = "/zoho/sync-refund";
const BULK_SYNC_REFUNDS_URL = "/zoho/bulk-sync-refunds";

export default function Refunds({
    shop,
    refunds = [],
    zohoConnected = false,
    host = "",
}) {
    const [loading, setLoading] = useState(true);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [refundList, setRefundList] = useState(refunds || []);
    const [search, setSearch] = useState("");
    const [filterStatus, setFilterStatus] = useState("all");
    const [syncingRefundId, setSyncingRefundId] = useState(null);
    const [notification, setNotification] = useState(null);
    const [selectedRefund, setSelectedRefund] = useState(null);

    // Bulk Selection State
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkSyncing, setBulkSyncing] = useState(false);
    const [bulkResultsModal, setBulkResultsModal] = useState(null);

    const headerCheckboxRef = useRef(null);

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

    const loadData = async () => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(REFUNDS_DATA_URL, { headers });
            const data = await response.json();

            if (response.ok && data.success) {
                setRefundList(data.refunds || []);
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean") {
                    setConnectedState(data.zohoConnected);
                }
            }
        } catch (error) {
            console.error("Failed to load refunds:", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    // Keep selectedRefund updated if refundList changes
    useEffect(() => {
        if (selectedRefund) {
            const updated = refundList.find((r) => r.id === selectedRefund.id);
            if (updated) {
                setSelectedRefund(updated);
            }
        }
    }, [refundList]);

    const handleRetrySync = async (refundId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingRefundId(refundId);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_REFUND_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ refund_id: refundId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: data.message || "Credit Note synchronized to Zoho successfully.",
                });
                await loadData();
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Credit Note sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during refund sync retry.",
            });
        } finally {
            setSyncingRefundId(null);
        }
    };

    const filteredRefunds = refundList.filter((r) => {
        const orderNum = r.order?.order_number || r.shopify_order_id || "";
        const customerName = r.order?.customer
            ? `${r.order.customer.first_name} ${r.order.customer.last_name} ${r.order.customer.email}`
            : "Guest Customer";
        const refundIdStr = String(r.shopify_refund_id || r.id);
        const creditNoteStr = String(r.creditnote_number || r.zoho_creditnote_id || "");

        const matchesSearch =
            refundIdStr.toLowerCase().includes(search.toLowerCase()) ||
            orderNum.toLowerCase().includes(search.toLowerCase()) ||
            customerName.toLowerCase().includes(search.toLowerCase()) ||
            creditNoteStr.toLowerCase().includes(search.toLowerCase());

        if (!matchesSearch) return false;

        const status = (r.sync_status || "pending").toLowerCase();

        if (filterStatus === "pending") return status === "pending";
        if (filterStatus === "synced") return status === "synced";
        if (filterStatus === "failed") return status === "failed";
        return true;
    });

    const visibleIds = filteredRefunds.map((r) => r.id);
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

    const handleBulkSync = async (onlyFailed = false) => {
        let idsToSync = selectedIds;
        if (onlyFailed) {
            const failedSet = new Set(
                refundList
                    .filter((r) => (r.sync_status || "").toLowerCase() === "failed")
                    .map((r) => r.id)
            );
            idsToSync = selectedIds.filter((id) => failedSet.has(id));
            if (idsToSync.length === 0) {
                idsToSync = selectedIds;
            }
        }

        if (idsToSync.length === 0) return;

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
            const response = await fetch(BULK_SYNC_REFUNDS_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({
                    refund_ids: idsToSync,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setBulkResultsModal({
                    summary: data.summary || {},
                    results: data.results || [],
                });
                await loadData();
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Bulk refund sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during bulk refund sync.",
            });
        } finally {
            setBulkSyncing(false);
        }
    };

    const counts = {
        all: refundList.length,
        pending: refundList.filter((r) => (r.sync_status || "pending").toLowerCase() === "pending").length,
        synced: refundList.filter((r) => (r.sync_status || "").toLowerCase() === "synced").length,
        failed: refundList.filter((r) => (r.sync_status || "").toLowerCase() === "failed").length,
    };

    const renderStatusBadge = (status) => {
        const s = (status || "pending").toLowerCase();
        let bg = "#fff8e6";
        let color = "#b78103";
        let border = "1px solid #ffe58f";
        let label = "PENDING";

        if (s === "synced") {
            bg = "#eafbdf";
            color = "#108043";
            border = "1px solid #b7eb8f";
            label = "SYNCED";
        } else if (s === "failed") {
            bg = "#fbeae8";
            color = "#d72c0d";
            border = "1px solid #f3baba";
            label = "FAILED";
        } else if (s === "skipped") {
            bg = "#f1f2f4";
            color = "#616a75";
            border = "1px solid #c9cccf";
            label = "SKIPPED";
        }

        return (
            <span
                style={{
                    padding: "3px 10px",
                    borderRadius: "12px",
                    fontSize: "11px",
                    fontWeight: 600,
                    backgroundColor: bg,
                    color: color,
                    border: border,
                }}
            >
                {label}
            </span>
        );
    };

    return (
        <ZohoLayout
            title="Refunds & Credit Notes | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="refunds"
        >
            <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
                {/* NOTIFICATION ALERT */}
                {notification && (
                    <div
                        style={{
                            padding: "12px 16px",
                            borderRadius: "8px",
                            fontSize: "14px",
                            fontWeight: 500,
                            backgroundColor:
                                notification.type === "success" ? "#eafbdf" : "#fbeae8",
                            color:
                                notification.type === "success" ? "#108043" : "#d72c0d",
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

                {/* PAGE HEADER */}
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
                            Refunds &amp; Credit Notes
                        </h1>
                        <p
                            style={{
                                fontSize: "14px",
                                color: "#616a75",
                                margin: "4px 0 0 0",
                            }}
                        >
                            Manage Shopify order refunds and Credit Note synchronization to Zoho Books.
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

                {/* FILTERS & SEARCH BAR */}
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
                    {/* FILTER TABS */}
                    <div style={{ display: "flex", gap: "8px" }}>
                        {[
                            { key: "all", label: `All (${counts.all})` },
                            { key: "pending", label: `Pending (${counts.pending})` },
                            { key: "synced", label: `Synced (${counts.synced})` },
                            { key: "failed", label: `Failed (${counts.failed})` },
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
                                    fontWeight: filterStatus === tab.key ? 600 : 500,
                                    backgroundColor:
                                        filterStatus === tab.key ? "#202223" : "#f1f2f4",
                                    color: filterStatus === tab.key ? "#ffffff" : "#616a75",
                                    cursor: "pointer",
                                }}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    {/* SEARCH INPUT */}
                    <input
                        type="text"
                        placeholder="Search Refund ID, Order #, Customer..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        style={{
                            padding: "8px 14px",
                            borderRadius: "6px",
                            border: "1px solid #c9cccf",
                            fontSize: "13px",
                            width: "300px",
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
                        }}
                    >
                        <div style={{ display: "flex", alignItems: "center", gap: "12px" }}>
                            <span style={{ fontSize: "14px", fontWeight: 600 }}>
                                ✓ {selectedIds.length} refund(s) selected
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

                        <div style={{ display: "flex", gap: "10px" }}>
                            <button
                                type="button"
                                onClick={() => handleBulkSync(false)}
                                disabled={bulkSyncing}
                                style={{
                                    padding: "8px 16px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#008060",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: bulkSyncing ? "wait" : "pointer",
                                }}
                            >
                                {bulkSyncing ? "Syncing..." : "Sync Selected Credit Notes"}
                            </button>

                            <button
                                type="button"
                                onClick={() => handleBulkSync(true)}
                                disabled={bulkSyncing}
                                style={{
                                    padding: "8px 16px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#005bd3",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: bulkSyncing ? "wait" : "pointer",
                                }}
                            >
                                {bulkSyncing ? "Retrying..." : "Retry Failed Syncs"}
                            </button>
                        </div>
                    </div>
                )}

                {/* REFUNDS TABLE */}
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
                                <th style={{ padding: "12px 16px" }}>REFUND ID</th>
                                <th style={{ padding: "12px 16px" }}>ORDER #</th>
                                <th style={{ padding: "12px 16px" }}>CUSTOMER</th>
                                <th style={{ padding: "12px 16px" }}>REFUND DATE</th>
                                <th style={{ padding: "12px 16px" }}>AMOUNT</th>
                                <th style={{ padding: "12px 16px" }}>RESTOCK</th>
                                <th style={{ padding: "12px 16px" }}>ZOHO CREDIT NOTE</th>
                                <th style={{ padding: "12px 16px" }}>SYNC STATUS</th>
                                <th style={{ padding: "12px 16px", textAlign: "right" }}>ACTIONS</th>
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
                                        Loading refunds data...
                                    </td>
                                </tr>
                            ) : filteredRefunds.length > 0 ? (
                                filteredRefunds.map((r) => {
                                    const isSyncing = syncingRefundId === r.id;
                                    const isSelected = selectedIds.includes(r.id);
                                    const isFailedOrPending =
                                        (r.sync_status || "pending").toLowerCase() === "failed" ||
                                        (r.sync_status || "pending").toLowerCase() === "pending";

                                    return (
                                        <tr
                                            key={r.id}
                                            style={{
                                                borderBottom: "1px solid #f1f2f4",
                                                backgroundColor: isSelected ? "#f4f6f8" : "transparent",
                                            }}
                                        >
                                            <td style={{ padding: "12px 16px", width: "40px" }}>
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => handleToggleSelectRow(r.id)}
                                                    style={{ cursor: "pointer", width: "16px", height: "16px" }}
                                                />
                                            </td>
                                            <td style={{ padding: "12px 16px", fontFamily: "monospace", fontWeight: 600 }}>
                                                {r.shopify_refund_id ? `#${r.shopify_refund_id}` : `#RF-${r.id}`}
                                            </td>
                                            <td style={{ padding: "12px 16px", fontWeight: 600, color: "#005bd3" }}>
                                                {r.order ? `#${r.order.order_number}` : `#${r.shopify_order_id}`}
                                            </td>
                                            <td style={{ padding: "12px 16px" }}>
                                                {r.order?.customer
                                                    ? `${r.order.customer.first_name || ""} ${r.order.customer.last_name || ""}`.trim() || r.order.customer.email
                                                    : "Guest Customer"}
                                            </td>
                                            <td style={{ padding: "12px 16px", color: "#616a75" }}>
                                                {r.created_at ? new Date(r.created_at).toLocaleDateString() : "—"}
                                            </td>
                                            <td style={{ padding: "12px 16px", fontWeight: 600 }}>
                                                ${parseFloat(r.amount || 0).toFixed(2)} {r.currency || "USD"}
                                            </td>
                                            <td style={{ padding: "12px 16px" }}>
                                                <span
                                                    style={{
                                                        padding: "2px 8px",
                                                        borderRadius: "10px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        backgroundColor: r.restock ? "#eafbdf" : "#f1f2f4",
                                                        color: r.restock ? "#108043" : "#616a75",
                                                    }}
                                                >
                                                    {r.restock ? "Yes" : "No"}
                                                </span>
                                            </td>
                                            <td style={{ padding: "12px 16px", fontFamily: "monospace" }}>
                                                {r.creditnote_number
                                                    ? r.creditnote_number
                                                    : r.zoho_creditnote_id
                                                    ? `CN-${r.zoho_creditnote_id}`
                                                    : "Not Synced"}
                                            </td>
                                            <td style={{ padding: "12px 16px" }}>
                                                {renderStatusBadge(r.sync_status)}
                                            </td>
                                            <td style={{ padding: "12px 16px", textAlign: "right" }}>
                                                <div style={{ display: "flex", gap: "8px", justifyContent: "flex-end" }}>
                                                    <button
                                                        type="button"
                                                        onClick={() => setSelectedRefund(r)}
                                                        style={{
                                                            padding: "6px 12px",
                                                            borderRadius: "6px",
                                                            border: "1px solid #c9cccf",
                                                            backgroundColor: "#ffffff",
                                                            fontSize: "12px",
                                                            fontWeight: 600,
                                                            color: "#202223",
                                                            cursor: "pointer",
                                                        }}
                                                    >
                                                        View Details
                                                    </button>
                                                    {isFailedOrPending && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRetrySync(r.id)}
                                                            disabled={isSyncing || bulkSyncing}
                                                            style={{
                                                                padding: "6px 12px",
                                                                borderRadius: "6px",
                                                                border: "1px solid #005bd3",
                                                                backgroundColor: "#005bd3",
                                                                fontSize: "12px",
                                                                fontWeight: 600,
                                                                color: "#ffffff",
                                                                cursor: isSyncing ? "wait" : "pointer",
                                                            }}
                                                        >
                                                            {isSyncing ? "Syncing..." : "Retry Sync"}
                                                        </button>
                                                    )}
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
                                        No refunds found matching your search.
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
                            Bulk Synchronization Results
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
                                        <th style={{ padding: "10px 14px" }}>Refund / ID</th>
                                        <th style={{ padding: "10px 14px" }}>Status</th>
                                        <th style={{ padding: "10px 14px" }}>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {bulkResultsModal.results?.map((res, idx) => (
                                        <tr key={idx} style={{ borderBottom: "1px solid #f1f2f4" }}>
                                            <td style={{ padding: "10px 14px", fontWeight: 600, fontFamily: "monospace" }}>
                                                {res.shopify_refund_id ? `#${res.shopify_refund_id}` : `ID #${res.id}`}
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

            {/* REFUND DETAILS MODAL */}
            {selectedRefund && (
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
                    onClick={() => setSelectedRefund(null)}
                >
                    <div
                        style={{
                            backgroundColor: "#ffffff",
                            borderRadius: "12px",
                            maxWidth: "650px",
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
                                    Refund Details — {selectedRefund.shopify_refund_id ? `#${selectedRefund.shopify_refund_id}` : `#RF-${selectedRefund.id}`}
                                </h2>
                                <p
                                    style={{
                                        fontSize: "13px",
                                        color: "#616a75",
                                        margin: "4px 0 0 0",
                                    }}
                                >
                                    Associated Order:{" "}
                                    <strong style={{ color: "#005bd3" }}>
                                        {selectedRefund.order ? `#${selectedRefund.order.order_number}` : `#${selectedRefund.shopify_order_id}`}
                                    </strong>
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedRefund(null)}
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
                                <div style={{ color: "#616a75", fontSize: "12px" }}>Customer</div>
                                <div style={{ fontWeight: 600, color: "#1a1d20", marginTop: "2px" }}>
                                    {selectedRefund.order?.customer
                                        ? `${selectedRefund.order.customer.first_name || ""} ${selectedRefund.order.customer.last_name || ""}`.trim() || selectedRefund.order.customer.email
                                        : "Guest Customer"}
                                </div>
                            </div>
                            <div style={{ backgroundColor: "#f8f9fa", padding: "12px", borderRadius: "8px" }}>
                                <div style={{ color: "#616a75", fontSize: "12px" }}>Refund Date</div>
                                <div style={{ fontWeight: 600, color: "#1a1d20", marginTop: "2px" }}>
                                    {selectedRefund.created_at ? new Date(selectedRefund.created_at).toLocaleString() : "—"}
                                </div>
                            </div>
                            <div style={{ backgroundColor: "#f8f9fa", padding: "12px", borderRadius: "8px" }}>
                                <div style={{ color: "#616a75", fontSize: "12px" }}>Refund Amount</div>
                                <div style={{ fontWeight: 700, color: "#1a1d20", marginTop: "2px", fontSize: "15px" }}>
                                    ${parseFloat(selectedRefund.amount || 0).toFixed(2)} {selectedRefund.currency || "USD"}
                                </div>
                            </div>
                            <div style={{ backgroundColor: "#f8f9fa", padding: "12px", borderRadius: "8px" }}>
                                <div style={{ color: "#616a75", fontSize: "12px" }}>Inventory Restocked</div>
                                <div style={{ fontWeight: 600, color: selectedRefund.restock ? "#108043" : "#616a75", marginTop: "2px" }}>
                                    {selectedRefund.restock ? "Yes (Inventory reversed)" : "No"}
                                </div>
                            </div>
                        </div>

                        {/* ZOHO CREDIT NOTE & SYNC STATUS */}
                        <div
                            style={{
                                backgroundColor: "#ffffff",
                                border: "1px solid #e1e3e5",
                                borderRadius: "8px",
                                padding: "16px",
                                marginBottom: "20px",
                            }}
                        >
                            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                                <div>
                                    <div style={{ fontSize: "12px", color: "#616a75" }}>Zoho Credit Note</div>
                                    <div style={{ fontWeight: 700, color: "#1a1d20", fontSize: "14px", marginTop: "2px", fontFamily: "monospace" }}>
                                        {selectedRefund.creditnote_number
                                            ? selectedRefund.creditnote_number
                                            : selectedRefund.zoho_creditnote_id
                                            ? `CN-${selectedRefund.zoho_creditnote_id}`
                                            : "Not Synced"}
                                    </div>
                                </div>
                                <div>{renderStatusBadge(selectedRefund.sync_status)}</div>
                            </div>

                            {/* ERROR MESSAGE IF FAILED */}
                            {(selectedRefund.sync_status || "").toLowerCase() === "failed" && selectedRefund.error_message && (
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
                                    <strong>Sync Error: </strong> {selectedRefund.error_message}
                                </div>
                            )}
                        </div>

                        {/* REFUNDED LINE ITEMS */}
                        <h3 style={{ fontSize: "14px", fontWeight: 600, color: "#1a1d20", marginBottom: "12px" }}>
                            Refunded Line Items
                        </h3>
                        {Array.isArray(selectedRefund.refund_line_items) && selectedRefund.refund_line_items.length > 0 ? (
                            <div style={{ border: "1px solid #e1e3e5", borderRadius: "8px", overflow: "hidden" }}>
                                <table style={{ width: "100%", borderCollapse: "collapse", fontSize: "12px" }}>
                                    <thead>
                                        <tr style={{ backgroundColor: "#f8f9fa", borderBottom: "1px solid #e1e3e5", textAlign: "left", color: "#616a75" }}>
                                            <th style={{ padding: "10px 12px" }}>Item Description</th>
                                            <th style={{ padding: "10px 12px", textAlign: "center" }}>Qty</th>
                                            <th style={{ padding: "10px 12px", textAlign: "right" }}>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {selectedRefund.refund_line_items.map((item, idx) => {
                                            const name = item.line_item?.title || item.line_item?.name || item.title || item.name || `Refund Item #${idx + 1}`;
                                            const qty = item.quantity || item.qty || 1;
                                            const subtotal = item.subtotal || item.price || 0;

                                            return (
                                                <tr key={idx} style={{ borderBottom: "1px solid #f1f2f4" }}>
                                                    <td style={{ padding: "10px 12px", fontWeight: 500 }}>{name}</td>
                                                    <td style={{ padding: "10px 12px", textAlign: "center" }}>{qty}</td>
                                                    <td style={{ padding: "10px 12px", textAlign: "right", fontWeight: 600 }}>
                                                        ${parseFloat(subtotal).toFixed(2)}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div style={{ fontSize: "13px", color: "#616a75", fontStyle: "italic", padding: "12px", backgroundColor: "#fafafa", borderRadius: "6px", border: "1px dashed #c9cccf" }}>
                                No specific line items detailed for this refund.
                            </div>
                        )}

                        {/* MODAL ACTIONS FOOTER */}
                        <div
                            style={{
                                marginTop: "20px",
                                borderTop: "1px solid #e1e3e5",
                                paddingTop: "16px",
                                display: "flex",
                                justifyContent: "space-between",
                                alignItems: "center",
                            }}
                        >
                            {(selectedRefund.sync_status || "pending").toLowerCase() !== "synced" ? (
                                <button
                                    type="button"
                                    onClick={() => handleRetrySync(selectedRefund.id)}
                                    disabled={syncingRefundId === selectedRefund.id || bulkSyncing}
                                    style={{
                                        padding: "8px 16px",
                                        borderRadius: "6px",
                                        border: "none",
                                        backgroundColor: "#005bd3",
                                        fontSize: "13px",
                                        fontWeight: 600,
                                        color: "#ffffff",
                                        cursor: syncingRefundId === selectedRefund.id ? "wait" : "pointer",
                                    }}
                                >
                                    {syncingRefundId === selectedRefund.id ? "Syncing to Zoho..." : "Retry Credit Note Sync"}
                                </button>
                            ) : (
                                <div />
                            )}

                            <button
                                type="button"
                                onClick={() => setSelectedRefund(null)}
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
