import React, { useEffect, useMemo, useState } from "react";
import { Head, router } from "@inertiajs/react";

export default function History({
    shop,
    histories,
    zohoConnected = false,
    pendingProducts = 0,
    filters = {},
}) {
    const historyData = histories?.data || [];

    const [search, setSearch] = useState(filters.search || "");
    const [statusFilter, setStatusFilter] = useState(filters.status || "all");

    /*
    |--------------------------------------------------------------------------
    | Server-side search + status filtering
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        const timeout = setTimeout(() => {
            router.get(
                "/zoho/sync/history",
                {
                    search,
                    status: statusFilter,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
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

    const getProductTitle = (history) =>
        history?.product_variant?.product?.title ||
        history?.product_title ||
        "Unknown Product";

    const getVariantTitle = (history) =>
        history?.product_variant?.title ||
        history?.variant_title ||
        "Default Variant";

    const getZohoId = (history) =>
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
    |
    | "Total Records" comes from the paginator.
    | Other metrics are based on the currently loaded page only.
    | We don't pretend the frontend knows the complete DB totals.
    |--------------------------------------------------------------------------
    */

    const metrics = useMemo(() => {
        const total = Number(histories?.total ?? 0) || historyData.length;

        const success = historyData.filter(
            (history) => getStatus(history) === "success",
        ).length;

        const failed = historyData.filter(
            (history) => getStatus(history) === "failed",
        ).length;

        const pending = Number(pendingProducts || 0);

        return {
            total,
            success,
            failed,
            pending,
        };
    }, [histories?.total, historyData, pendingProducts]);

    const hasActiveFilters = Boolean(search.trim()) || statusFilter !== "all";

    /*
    |--------------------------------------------------------------------------
    | Reset filters
    |--------------------------------------------------------------------------
    */

    const clearFilters = () => {
        setSearch("");
        setStatusFilter("all");
    };

    /*
    |--------------------------------------------------------------------------
    | Refresh
    |--------------------------------------------------------------------------
    */

    const refreshPage = () => {
        router.get(
            "/zoho/sync/history",
            {
                search,
                status: statusFilter,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <>
            <Head title="Activity Logs" />

            <div className="zoho-page">
                {/* =====================================================
                    HEADER
                ====================================================== */}

                <header className="zoho-header">
                    <div className="zoho-header-left">
                        <div className="zoho-brand-mark">Z</div>

                        <div>
                            <div className="zoho-header-title">
                                Zoho Books Integration
                            </div>

                            <div className="zoho-header-subtitle">
                                Shopify Store:{" "}
                                {shop?.shop_domain || "Unknown store"}
                            </div>
                        </div>
                    </div>

                    <div
                        className={
                            zohoConnected
                                ? "connection-badge"
                                : "connection-badge disconnected"
                        }
                    >
                        <span className="connection-dot" />

                        {zohoConnected ? "Connected" : "Not Connected"}
                    </div>
                </header>

                {/* =====================================================
                    CONTENT
                ====================================================== */}

                <main className="zoho-content">
                    {/* PAGE HEADER */}

                    <section className="page-intro history-page-intro">
                        <div>
                            <span className="eyebrow">ACTIVITY</span>

                            <h1>Activity Logs</h1>

                            <p>
                                Monitor Shopify to Zoho Books synchronization
                                activity and results.
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

                    {/* =================================================
                        METRICS
                    ================================================== */}

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
                                <span>Success on Page</span>

                                <strong className="metric-success">
                                    {metrics.success}
                                </strong>
                            </div>
                        </div>

                        <div className="history-metric-card">
                            <div className="history-metric-icon danger">!</div>

                            <div>
                                <span>Failed on Page</span>

                                <strong className="metric-danger">
                                    {metrics.failed}
                                </strong>
                            </div>
                        </div>

                        <div className="history-metric-card">
                            <div className="history-metric-icon warning">○</div>

                            <div>
                                <span>Pending Variants</span>

                                <strong className="metric-warning">
                                    {metrics.pending}
                                </strong>
                            </div>
                        </div>
                    </section>

                    {/* =================================================
                        ACTIVITY CARD
                    ================================================== */}

                    <section className="history-card modern-history-card">
                        {/* CARD HEADER */}

                        <div className="modern-history-header">
                            <div>
                                <h2>Synchronization Activity</h2>

                                <p>
                                    {historyData.length}{" "}
                                    {historyData.length === 1
                                        ? "record"
                                        : "records"}{" "}
                                    currently displayed
                                </p>
                            </div>

                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    className="clear-history-filter-btn"
                                    onClick={clearFilters}
                                >
                                    Clear filters
                                </button>
                            )}
                        </div>

                        {/* =================================================
                            FILTER TOOLBAR
                        ================================================== */}

                        <div className="history-filter-toolbar">
                            <div className="history-search-wrap">
                                <span className="history-search-icon">⌕</span>

                                <input
                                    type="text"
                                    className="history-search-input"
                                    placeholder="Search product, SKU, Zoho ID or message..."
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />

                                {search && (
                                    <button
                                        type="button"
                                        className="history-search-clear"
                                        onClick={() => setSearch("")}
                                        aria-label="Clear search"
                                    >
                                        ×
                                    </button>
                                )}
                            </div>

                            <select
                                className="history-status-select"
                                value={statusFilter}
                                onChange={(event) =>
                                    setStatusFilter(event.target.value)
                                }
                            >
                                <option value="all">All Status</option>

                                <option value="success">Success</option>

                                <option value="failed">Failed</option>

                                <option value="pending">Pending</option>

                                <option value="skipped">Skipped</option>
                            </select>
                        </div>

                        {/* =================================================
                            FILTER SUMMARY
                        ================================================== */}

                        <div className="history-filter-summary">
                            <span>
                                {hasActiveFilters
                                    ? "Filtered activity"
                                    : "All synchronization activity"}
                            </span>

                            {hasActiveFilters && (
                                <span className="history-active-filter">
                                    Active filters
                                </span>
                            )}
                        </div>

                        {/* =================================================
                            EMPTY
                        ================================================== */}

                        {historyData.length === 0 ? (
                            <div className="empty-state modern-history-empty">
                                <div className="empty-state-icon">↻</div>

                                <strong>
                                    {hasActiveFilters
                                        ? "No matching activity"
                                        : "No synchronization history"}
                                </strong>

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
                                            <th>PRODUCT</th>

                                            <th>ACTION</th>

                                            <th>STATUS</th>

                                            <th>ZOHO ITEM</th>

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
                                                history?.message ||
                                                "No message";

                                            const date = formatDate(
                                                history?.created_at,
                                            );

                                            return (
                                                <tr key={history.id}>
                                                    {/* PRODUCT */}

                                                    <td>
                                                        <div className="product-cell history-product-cell">
                                                            <div className="product-avatar history-product-avatar">
                                                                {productTitle
                                                                    .charAt(0)
                                                                    .toUpperCase()}
                                                            </div>

                                                            <div className="history-product-info">
                                                                <div className="product-name">
                                                                    {
                                                                        productTitle
                                                                    }
                                                                </div>

                                                                <div className="variant-name">
                                                                    {
                                                                        variantTitle
                                                                    }
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>

                                                    {/* ACTION */}

                                                    <td>
                                                        <span
                                                            className={`history-action ${action}`}
                                                        >
                                                            {formatLabel(
                                                                action,
                                                            )}
                                                        </span>
                                                    </td>

                                                    {/* STATUS */}

                                                    <td>
                                                        <span
                                                            className={`status ${status}`}
                                                        >
                                                            <span className="status-dot" />

                                                            {formatLabel(
                                                                status,
                                                            )}
                                                        </span>
                                                    </td>

                                                    {/* ZOHO ID */}

                                                    <td>
                                                        {zohoId ? (
                                                            <span
                                                                className="zoho-id history-zoho-id"
                                                                title={String(
                                                                    zohoId,
                                                                )}
                                                            >
                                                                {zohoId}
                                                            </span>
                                                        ) : (
                                                            <span className="empty-id">
                                                                —
                                                            </span>
                                                        )}
                                                    </td>

                                                    {/* MESSAGE */}

                                                    <td>
                                                        <span
                                                            className={
                                                                status ===
                                                                "failed"
                                                                    ? "history-message failed-message"
                                                                    : "history-message"
                                                            }
                                                            title={message}
                                                        >
                                                            {message}
                                                        </span>
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

                    {/* =================================================
                        PAGINATION
                    ================================================== */}

                    {histories?.last_page > 1 && (
                        <div className="history-pagination">
                            <div className="history-pagination-info">
                                Page <strong>{histories.current_page}</strong>{" "}
                                of <strong>{histories.last_page}</strong>
                            </div>

                            <div className="pagination">
                                {histories.links.map((link, index) => (
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

                                            router.get(
                                                link.url,
                                                {},
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            );
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
            </div>
        </>
    );
}
