import React, { useEffect, useMemo, useState } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

export default function History({
    shop,
    histories,
    zohoConnected = false,
    pendingProducts = 0,
    filters = {},
    host = "",
}) {
    const [shopData, setShopData] = useState(shop || {});
    const [historiesState, setHistoriesState] = useState(
        histories || { data: [], total: 0 },
    );
    const [zohoConn, setZohoConn] = useState(zohoConnected);
    const [pendingCount, setPendingCount] = useState(pendingProducts);
    const [loading, setLoading] = useState(true);

    const historyData = historiesState?.data || [];

    const [search, setSearch] = useState(filters.search || "");
    const [statusFilter, setStatusFilter] = useState(filters.status || "all");

    const loadData = async (
        page = 1,
        searchQuery = search,
        statusVal = statusFilter,
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
            });
            const response = await fetch(
                `/api/zoho/sync/history?${params.toString()}`,
                { headers },
            );
            const data = await response.json();
            if (response.ok && data.success) {
                if (data.shop) setShopData(data.shop);
                if (data.histories) setHistoriesState(data.histories);
                if (typeof data.zohoConnected === "boolean")
                    setZohoConn(data.zohoConnected);
                if (typeof data.pendingProducts === "number")
                    setPendingCount(data.pendingProducts);
            }
        } catch (error) {
            console.error("Failed to load history data:", error);
        } finally {
            setLoading(false);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Server-side search + status filtering
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        const timeout = setTimeout(() => {
            loadData(1, search, statusFilter);
        }, 350);

        return () => clearTimeout(timeout);
    }, [search, statusFilter]);

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    const formatLabel = (value) => {
        if (!value) {
            return "Unknown";
        }

        const normalized = String(value).replace(/[_-]+/g, " ").trim();

        return normalized.replace(/\b\w/g, (char) => char.toUpperCase());
    };

    const formatDate = (value) => {
        if (!value) {
            return {
                date: "—",
                time: "",
            };
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return {
                date: "—",
                time: "",
            };
        }

        return {
            date: date.toLocaleDateString(),
            time: date.toLocaleTimeString([], {
                hour: "2-digit",
                minute: "2-digit",
            }),
        };
    };

    const getProductTitle = (history) => {
        if (history?.payment) {
            return `Payment #${history.payment.payment_reference || history.payment.shopify_transaction_id || history.payment.id}`;
        }
        if (history?.invoice) {
            return `Invoice #${history.invoice.zoho_invoice_id || history.invoice.id}`;
        }
        if (history?.order) {
            return `Order ${history.order.name || `#${history.order.order_number}`}`;
        }
        return (
            history?.product_variant?.product?.title ||
            history?.product_title ||
            "Item Sync"
        );
    };

    const getVariantTitle = (history) => {
        if (history?.payment) {
            const orderName = history.order?.name || (history.order?.order_number ? `#${history.order.order_number}` : `Order #${history.payment.order_id}`);
            return `${orderName} • $${parseFloat(history.payment.amount || 0).toFixed(2)}`;
        }
        if (history?.invoice || history?.order) {
            const cust = history.order?.customer;
            return cust ? `Customer: ${cust.first_name} ${cust.last_name}` : "Guest Customer";
        }
        return (
            history?.product_variant?.title ||
            history?.variant_title ||
            "Default Variant"
        );
    };

    const getZohoId = (history) =>
        history?.zoho_payment_id ??
        history?.payment?.zoho_payment_id ??
        history?.zoho_invoice_id ??
        history?.invoice?.zoho_invoice_id ??
        history?.zoho_item_id ??
        history?.product_variant?.zoho_item_id ??
        history?.zoho_id ??
        null;

    const getStatus = (history) => {
        const status = String(history?.status || "pending").toLowerCase();

        if (["success", "successful", "synced"].includes(status)) {
            return "success";
        }

        if (["failed", "error"].includes(status)) {
            return "failed";
        }

        if (["skipped", "skip"].includes(status)) {
            return "skipped";
        }

        return "pending";
    };

    const getAction = (history) =>
        String(history?.action || "unknown").toLowerCase();

    /*
    |--------------------------------------------------------------------------
    | Dynamic page metrics
    |--------------------------------------------------------------------------
    */

    const metrics = useMemo(() => {
        const total = Number(historiesState?.total ?? 0) || historyData.length;

        const success = historyData.filter(
            (history) => getStatus(history) === "success",
        ).length;

        const failed = historyData.filter(
            (history) => getStatus(history) === "failed",
        ).length;

        const pending = Number(pendingCount || 0);

        return {
            total,
            success,
            failed,
            pending,
        };
    }, [historiesState?.total, historyData, pendingCount]);

    const hasActiveFilters = Boolean(search.trim()) || statusFilter !== "all";

    const clearFilters = () => {
        setSearch("");
        setStatusFilter("all");
    };

    const refreshPage = () => {
        loadData(historiesState?.current_page || 1, search, statusFilter);
    };

    const getStatusPillStyle = (status) => {
        switch (status) {
            case "success":
                return {
                    backgroundColor: "#eafbdf",
                    color: "#108043",
                    border: "1px solid #b7eb8f",
                };
            case "failed":
                return {
                    backgroundColor: "#fbeae8",
                    color: "#d72c0d",
                    border: "1px solid #f3baba",
                };
            case "skipped":
                return {
                    backgroundColor: "#f1f2f4",
                    color: "#616a75",
                    border: "1px solid #c9cccf",
                };
            default:
                return {
                    backgroundColor: "#fff8e6",
                    color: "#b78103",
                    border: "1px solid #ffe58f",
                };
        }
    };

    return (
        <ZohoLayout
            title="Sync History | Zoho Books Integration"
            shop={shopData}
            zohoConnected={zohoConn}
            host={host}
            activePage="history"
        >
            <div
                style={{
                    display: "flex",
                    flexDirection: "column",
                    gap: "20px",
                }}
            >
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
                            Activity Logs
                        </h1>
                        <p
                            style={{
                                fontSize: "14px",
                                color: "#616a75",
                                margin: "4px 0 0 0",
                            }}
                        >
                            Monitor Shopify to Zoho Books synchronization activity and execution logs.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={refreshPage}
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

                {/* METRICS CARDS */}
                <div
                    style={{
                        display: "grid",
                        gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))",
                        gap: "16px",
                    }}
                >
                    <div
                        style={{
                            backgroundColor: "#ffffff",
                            borderRadius: "10px",
                            padding: "16px 20px",
                            border: "1px solid #e1e3e5",
                            display: "flex",
                            alignItems: "center",
                            gap: "14px",
                        }}
                    >
                        <div
                            style={{
                                width: "40px",
                                height: "40px",
                                borderRadius: "8px",
                                backgroundColor: "#f1f2f4",
                                color: "#202223",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontSize: "16px",
                                fontWeight: 700,
                            }}
                        >
                            #
                        </div>
                        <div>
                            <span
                                style={{
                                    fontSize: "12px",
                                    color: "#616a75",
                                    fontWeight: 500,
                                    textTransform: "uppercase",
                                    letterSpacing: "0.5px",
                                }}
                            >
                                Total Records
                            </span>
                            <div
                                style={{
                                    fontSize: "22px",
                                    fontWeight: 700,
                                    color: "#202223",
                                    lineHeight: "1.2",
                                }}
                            >
                                {metrics.total}
                            </div>
                        </div>
                    </div>

                    <div
                        style={{
                            backgroundColor: "#ffffff",
                            borderRadius: "10px",
                            padding: "16px 20px",
                            border: "1px solid #e1e3e5",
                            display: "flex",
                            alignItems: "center",
                            gap: "14px",
                        }}
                    >
                        <div
                            style={{
                                width: "40px",
                                height: "40px",
                                borderRadius: "8px",
                                backgroundColor: "#eafbdf",
                                color: "#108043",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontSize: "16px",
                                fontWeight: 700,
                            }}
                        >
                            ✓
                        </div>
                        <div>
                            <span
                                style={{
                                    fontSize: "12px",
                                    color: "#616a75",
                                    fontWeight: 500,
                                    textTransform: "uppercase",
                                    letterSpacing: "0.5px",
                                }}
                            >
                                Synced Items
                            </span>
                            <div
                                style={{
                                    fontSize: "22px",
                                    fontWeight: 700,
                                    color: "#202223",
                                    lineHeight: "1.2",
                                }}
                            >
                                {metrics.success}
                            </div>
                        </div>
                    </div>

                    <div
                        style={{
                            backgroundColor: "#ffffff",
                            borderRadius: "10px",
                            padding: "16px 20px",
                            border: "1px solid #e1e3e5",
                            display: "flex",
                            alignItems: "center",
                            gap: "14px",
                        }}
                    >
                        <div
                            style={{
                                width: "40px",
                                height: "40px",
                                borderRadius: "8px",
                                backgroundColor: "#fff8e6",
                                color: "#b78103",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontSize: "16px",
                                fontWeight: 700,
                            }}
                        >
                            !
                        </div>
                        <div>
                            <span
                                style={{
                                    fontSize: "12px",
                                    color: "#616a75",
                                    fontWeight: 500,
                                    textTransform: "uppercase",
                                    letterSpacing: "0.5px",
                                }}
                            >
                                Pending Catalog
                            </span>
                            <div
                                style={{
                                    fontSize: "22px",
                                    fontWeight: 700,
                                    color: "#202223",
                                    lineHeight: "1.2",
                                }}
                            >
                                {metrics.pending}
                            </div>
                        </div>
                    </div>

                    <div
                        style={{
                            backgroundColor: "#ffffff",
                            borderRadius: "10px",
                            padding: "16px 20px",
                            border: "1px solid #e1e3e5",
                            display: "flex",
                            alignItems: "center",
                            gap: "14px",
                        }}
                    >
                        <div
                            style={{
                                width: "40px",
                                height: "40px",
                                borderRadius: "8px",
                                backgroundColor: "#fbeae8",
                                color: "#d72c0d",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontSize: "16px",
                                fontWeight: 700,
                            }}
                        >
                            ✕
                        </div>
                        <div>
                            <span
                                style={{
                                    fontSize: "12px",
                                    color: "#616a75",
                                    fontWeight: 500,
                                    textTransform: "uppercase",
                                    letterSpacing: "0.5px",
                                }}
                            >
                                Failed Logs
                            </span>
                            <div
                                style={{
                                    fontSize: "22px",
                                    fontWeight: 700,
                                    color: "#202223",
                                    lineHeight: "1.2",
                                }}
                            >
                                {metrics.failed}
                            </div>
                        </div>
                    </div>
                </div>

                {/* SEARCH & STATUS CHIPS BAR */}
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
                            { label: "All Records", value: "all" },
                            { label: "Synced", value: "synced" },
                            { label: "Pending", value: "pending" },
                            { label: "Failed", value: "failed" },
                        ].map((chip) => (
                            <button
                                key={chip.value}
                                type="button"
                                onClick={() => setStatusFilter(chip.value)}
                                style={{
                                    padding: "6px 14px",
                                    borderRadius: "20px",
                                    border: "none",
                                    fontSize: "13px",
                                    fontWeight:
                                        statusFilter === chip.value ? 600 : 500,
                                    backgroundColor:
                                        statusFilter === chip.value
                                            ? "#202223"
                                            : "#f1f2f4",
                                    color:
                                        statusFilter === chip.value
                                            ? "#ffffff"
                                            : "#616a75",
                                    cursor: "pointer",
                                }}
                            >
                                {chip.label}
                            </button>
                        ))}
                    </div>

                    <div style={{ display: "flex", gap: "8px", alignItems: "center" }}>
                        <input
                            type="text"
                            placeholder="Search title, order #, payment ref, or Zoho ID..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            style={{
                                padding: "8px 14px",
                                borderRadius: "6px",
                                border: "1px solid #c9cccf",
                                fontSize: "13px",
                                width: "320px",
                                boxSizing: "border-box",
                            }}
                        />

                        {search && (
                            <button
                                type="button"
                                onClick={() => setSearch("")}
                                style={{
                                    padding: "8px 12px",
                                    borderRadius: "6px",
                                    border: "1px solid #c9cccf",
                                    backgroundColor: "#ffffff",
                                    color: "#616a75",
                                    fontSize: "12px",
                                    cursor: "pointer",
                                }}
                            >
                                Clear
                            </button>
                        )}
                    </div>
                </div>

                {/* LOGS TABLE CONTAINER */}
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
                                <th style={{ padding: "12px 16px", fontSize: "12px", fontWeight: 600 }}>
                                    ITEM / REFERENCE
                                </th>
                                <th style={{ padding: "12px 16px", fontSize: "12px", fontWeight: 600 }}>
                                    ACTION
                                </th>
                                <th style={{ padding: "12px 16px", fontSize: "12px", fontWeight: 600 }}>
                                    STATUS
                                </th>
                                <th style={{ padding: "12px 16px", fontSize: "12px", fontWeight: 600 }}>
                                    ZOHO REFERENCE
                                </th>
                                <th style={{ padding: "12px 16px", fontSize: "12px", fontWeight: 600 }}>
                                    MESSAGE
                                </th>
                                <th style={{ padding: "12px 16px", fontSize: "12px", fontWeight: 600 }}>
                                    DATE
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {loading && historyData.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        style={{
                                            textAlign: "center",
                                            padding: "40px",
                                            color: "#616a75",
                                        }}
                                    >
                                        Loading sync history...
                                    </td>
                                </tr>
                            ) : historyData.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        style={{
                                            textAlign: "center",
                                            padding: "48px 24px",
                                        }}
                                    >
                                        <div style={{ fontSize: "32px", marginBottom: "12px" }}>📋</div>
                                        <div style={{ fontSize: "15px", fontWeight: 600, color: "#202223", marginBottom: "4px" }}>
                                            No synchronization logs found
                                        </div>
                                        <p style={{ fontSize: "13px", color: "#616a75", margin: "0 0 16px 0" }}>
                                            {hasActiveFilters
                                                ? "Try changing your search query or status filter."
                                                : "Your synchronization activity will appear here."}
                                        </p>
                                        {hasActiveFilters && (
                                            <button
                                                type="button"
                                                onClick={clearFilters}
                                                style={{
                                                    padding: "6px 14px",
                                                    borderRadius: "6px",
                                                    border: "1px solid #c9cccf",
                                                    backgroundColor: "#ffffff",
                                                    fontSize: "13px",
                                                    fontWeight: 500,
                                                    color: "#202223",
                                                    cursor: "pointer",
                                                }}
                                            >
                                                Clear filters
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ) : (
                                historyData.map((history) => {
                                    const productTitle = getProductTitle(history);
                                    const variantTitle = getVariantTitle(history);
                                    const status = getStatus(history);
                                    const action = getAction(history);
                                    const zohoId = getZohoId(history);
                                    const message = history?.message || "No message";
                                    const date = formatDate(history?.created_at);
                                    const pillStyle = getStatusPillStyle(status);

                                    return (
                                        <tr
                                            key={history.id}
                                            style={{
                                                borderBottom: "1px solid #f1f2f4",
                                            }}
                                        >
                                            {/* ITEM / REFERENCE */}
                                            <td style={{ padding: "12px 16px" }}>
                                                <div
                                                    style={{
                                                        fontWeight: 600,
                                                        color: "#1a1d20",
                                                        fontSize: "13px",
                                                    }}
                                                >
                                                    {productTitle}
                                                </div>
                                                <div
                                                    style={{
                                                        fontSize: "12px",
                                                        color: "#616a75",
                                                        marginTop: "2px",
                                                    }}
                                                >
                                                    {variantTitle}
                                                </div>
                                            </td>

                                            {/* ACTION */}
                                            <td style={{ padding: "12px 16px" }}>
                                                <span
                                                    style={{
                                                        padding: "3px 8px",
                                                        borderRadius: "4px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        backgroundColor: "#f1f2f4",
                                                        color: "#303030",
                                                        textTransform: "uppercase",
                                                        letterSpacing: "0.5px",
                                                    }}
                                                >
                                                    {formatLabel(action)}
                                                </span>
                                            </td>

                                            {/* STATUS */}
                                            <td style={{ padding: "12px 16px" }}>
                                                <span
                                                    style={{
                                                        display: "inline-flex",
                                                        alignItems: "center",
                                                        gap: "6px",
                                                        padding: "3px 10px",
                                                        borderRadius: "12px",
                                                        fontSize: "12px",
                                                        fontWeight: 500,
                                                        ...pillStyle,
                                                    }}
                                                >
                                                    <span
                                                        style={{
                                                            width: "6px",
                                                            height: "6px",
                                                            borderRadius: "50%",
                                                            backgroundColor: "currentColor",
                                                        }}
                                                    />
                                                    {formatLabel(status)}
                                                </span>
                                            </td>

                                            {/* ZOHO REFERENCE */}
                                            <td style={{ padding: "12px 16px" }}>
                                                {zohoId ? (
                                                    <span
                                                        style={{
                                                            fontFamily: "monospace",
                                                            fontSize: "12px",
                                                            color: "#005bd3",
                                                            backgroundColor: "#e8f4fe",
                                                            padding: "2px 6px",
                                                            borderRadius: "4px",
                                                            display: "inline-block",
                                                        }}
                                                        title={String(zohoId)}
                                                    >
                                                        {zohoId}
                                                    </span>
                                                ) : (
                                                    <span
                                                        style={{
                                                            fontSize: "12px",
                                                            color: "#8c9196",
                                                            fontStyle: "italic",
                                                        }}
                                                    >
                                                        Not Created
                                                    </span>
                                                )}
                                            </td>

                                            {/* MESSAGE */}
                                            <td style={{ padding: "12px 16px" }}>
                                                <div
                                                    style={{
                                                        fontSize: "12px",
                                                        color: "#4a4f56",
                                                        maxWidth: "280px",
                                                        overflow: "hidden",
                                                        textOverflow: "ellipsis",
                                                        whiteSpace: "nowrap",
                                                    }}
                                                    title={message}
                                                >
                                                    {message}
                                                </div>
                                            </td>

                                            {/* DATE */}
                                            <td style={{ padding: "12px 16px" }}>
                                                <div
                                                    style={{
                                                        fontSize: "13px",
                                                        fontWeight: 500,
                                                        color: "#1a1d20",
                                                    }}
                                                >
                                                    {date.date}
                                                </div>
                                                <div
                                                    style={{
                                                        fontSize: "11px",
                                                        color: "#616a75",
                                                    }}
                                                >
                                                    {date.time}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* PAGINATION */}
                {historiesState?.last_page > 1 && (
                    <div
                        style={{
                            display: "flex",
                            justifyContent: "space-between",
                            alignItems: "center",
                            padding: "16px",
                            backgroundColor: "#ffffff",
                            borderRadius: "10px",
                            border: "1px solid #e1e3e5",
                        }}
                    >
                        <div style={{ fontSize: "13px", color: "#616a75" }}>
                            Page <strong>{historiesState.current_page}</strong> of{" "}
                            <strong>{historiesState.last_page}</strong>
                        </div>

                        <div style={{ display: "flex", gap: "4px" }}>
                            {historiesState.links.map((link, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    disabled={!link.url}
                                    style={{
                                        padding: "6px 12px",
                                        borderRadius: "6px",
                                        border: link.active
                                            ? "1px solid #202223"
                                            : "1px solid #c9cccf",
                                        backgroundColor: link.active
                                            ? "#202223"
                                            : "#ffffff",
                                        color: link.active
                                            ? "#ffffff"
                                            : "#202223",
                                        fontSize: "12px",
                                        fontWeight: 500,
                                        cursor: !link.url ? "default" : "pointer",
                                        opacity: !link.url ? 0.5 : 1,
                                    }}
                                    onClick={() => {
                                        if (!link.url) return;

                                        try {
                                            const url = new URL(
                                                link.url,
                                                window.location.origin,
                                            );
                                            const pageParam =
                                                url.searchParams.get("page") || 1;
                                            loadData(
                                                pageParam,
                                                search,
                                                statusFilter,
                                            );
                                        } catch {
                                            loadData(1, search, statusFilter);
                                        }
                                    }}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </ZohoLayout>
    );
}
