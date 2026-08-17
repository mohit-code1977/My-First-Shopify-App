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

    return (
        <ZohoLayout
            title="Sync History | Zoho Books Integration"
            shop={shopData}
            zohoConnected={zohoConn}
            host={host}
            activePage="history"
        >
            <main className="zoho-content" style={{ padding: 0 }}>
                {/* PAGE HEADER */}

                <section className="page-intro history-page-intro">
                    <div>
                        <span className="eyebrow">ACTIVITY</span>

                        <h1>Activity Logs</h1>

                        <p>
                            Monitor Shopify to Zoho Books synchronization activity and results.
                        </p>
                    </div>

                    <button
                        type="button"
                        className="history-refresh-btn"
                        onClick={refreshPage}
                    >
                        <span>↻</span>
                        Refresh
                    </button>
                </section>

                {/* METRICS */}

                <section className="history-metrics">
                    <div className="history-metric-card">
                        <div className="history-metric-icon neutral">#</div>

                        <div>
                            <span>Total Records</span>
                            <strong>{metrics.total}</strong>
                        </div>
                    </div>

                    <div className="history-metric-card">
                        <div className="history-metric-icon success">✓</div>

                        <div>
                            <span>Synced Items</span>
                            <strong>{metrics.success}</strong>
                        </div>
                    </div>

                    <div className="history-metric-card">
                        <div className="history-metric-icon warning">!</div>

                        <div>
                            <span>Pending Catalog</span>
                            <strong>{metrics.pending}</strong>
                        </div>
                    </div>

                    <div className="history-metric-card">
                        <div className="history-metric-icon error">✕</div>

                        <div>
                            <span>Failed Logs</span>
                            <strong>{metrics.failed}</strong>
                        </div>
                    </div>
                </section>

                {/* FILTERS */}

                <section className="history-filters-card">
                    <div className="history-search-group">
                        <input
                            type="text"
                            className="history-search-input"
                            placeholder="Search by title, SKU, order #, payment reference, or Zoho ID..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />

                        {search && (
                            <button
                                type="button"
                                className="history-search-clear"
                                onClick={() => setSearch("")}
                            >
                                ✕
                            </button>
                        )}
                    </div>

                    <div className="history-filter-chips">
                        {[
                            { label: "All", value: "all" },
                            { label: "Synced", value: "synced" },
                            { label: "Pending", value: "pending" },
                            { label: "Failed", value: "failed" },
                        ].map((chip) => (
                            <button
                                key={chip.value}
                                type="button"
                                className={`history-chip ${
                                    statusFilter === chip.value ? "active" : ""
                                }`}
                                onClick={() => setStatusFilter(chip.value)}
                            >
                                {chip.label}
                            </button>
                        ))}
                    </div>
                </section>

                {/* TABLE */}

                <section className="history-table-section">
                    {loading && historyData.length === 0 ? (
                        <div style={{ textAlign: "center", padding: "40px", color: "#616a75" }}>
                            Loading sync history...
                        </div>
                    ) : historyData.length === 0 ? (
                        <div className="empty-history-card">
                            <div className="empty-history-icon">📋</div>

                            <strong>No synchronization logs found</strong>

                            <p>
                                {hasActiveFilters
                                    ? "Try changing your search or status filter."
                                    : "Your synchronization activity will appear here."}
                            </p>

                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    className="empty-clear-btn"
                                    onClick={clearFilters}
                                >
                                    Clear filters
                                </button>
                            )}
                        </div>
                    ) : (
                        <div className="table-wrapper history-modern-table-wrapper">
                            <table className="history-table modern-history-table">
                                <thead>
                                    <tr>
                                        <th>ITEM / REFERENCE</th>

                                        <th>ACTION</th>

                                        <th>STATUS</th>

                                        <th>ZOHO REFERENCE</th>

                                        <th>MESSAGE</th>

                                        <th>DATE</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {historyData.map((history) => {
                                        const productTitle =
                                            getProductTitle(history);

                                        const variantTitle =
                                            getVariantTitle(history);

                                        const status = getStatus(history);

                                        const action = getAction(history);

                                        const zohoId = getZohoId(history);

                                        const message =
                                            history?.message || "No message";

                                        const date = formatDate(
                                            history?.created_at,
                                        );

                                        return (
                                            <tr key={history.id}>
                                                {/* ITEM */}

                                                <td>
                                                    <div className="product-cell history-product-cell">
                                                        <div className="product-avatar history-product-avatar">
                                                            {productTitle
                                                                .charAt(0)
                                                                .toUpperCase()}
                                                        </div>

                                                        <div className="history-product-info">
                                                            <div className="product-name">
                                                                {productTitle}
                                                            </div>

                                                            <div className="variant-name">
                                                                {variantTitle}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* ACTION */}

                                                <td>
                                                    <span
                                                        className={`history-action ${action}`}
                                                    >
                                                        {formatLabel(action)}
                                                    </span>
                                                </td>

                                                {/* STATUS */}

                                                <td>
                                                    <span
                                                        className={`status ${status}`}
                                                    >
                                                        <span className="status-dot" />

                                                        {formatLabel(status)}
                                                    </span>
                                                </td>

                                                {/* ZOHO ID */}

                                                <td>
                                                    {zohoId ? (
                                                        <span
                                                            className="zoho-id history-zoho-id"
                                                            title={String(zohoId)}
                                                        >
                                                            {zohoId}
                                                        </span>
                                                    ) : (
                                                        <span className="zoho-id empty">
                                                            Not Created
                                                        </span>
                                                    )}
                                                </td>

                                                {/* MESSAGE */}

                                                <td>
                                                    <div
                                                        className="history-message"
                                                        title={message}
                                                    >
                                                        {message}
                                                    </div>
                                                </td>

                                                {/* DATE */}

                                                <td>
                                                    <div className="history-date">
                                                        {date.date}
                                                    </div>

                                                    <div className="history-time">
                                                        {date.time}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {/* PAGINATION */}

                {historiesState?.last_page > 1 && (
                    <div className="history-pagination">
                        <div className="history-pagination-info">
                            Page <strong>{historiesState.current_page}</strong>{" "}
                            of <strong>{historiesState.last_page}</strong>
                        </div>

                        <div className="pagination">
                            {historiesState.links.map((link, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    disabled={!link.url}
                                    className={
                                        link.active
                                            ? "pagination-btn active"
                                            : "pagination-btn"
                                    }
                                    onClick={() => {
                                        if (!link.url) {
                                            return;
                                        }

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
            </main>
        </ZohoLayout>
    );
}
