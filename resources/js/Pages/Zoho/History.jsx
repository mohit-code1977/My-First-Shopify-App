import React, { useMemo, useState } from "react";

import { Head } from "@inertiajs/react";

export default function History({ shop, histories, zohoConnected = false }) {
    const historyData = histories?.data || [];

    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("all");

    const filteredHistory = useMemo(() => {
        const query = search.trim().toLowerCase();

        return historyData.filter((history) => {
            const product = history.product_variant?.product?.title?.toLowerCase() || "";

            const variant = history.product_variant?.title?.toLowerCase() || "";

            const action = history.action?.toLowerCase() || "";

            const status = history.status?.toLowerCase() || "";

            const message = history.message?.toLowerCase() || "";

            const matchesSearch =
                !query ||
                product.includes(query) ||
                variant.includes(query) ||
                action.includes(query) ||
                status.includes(query) ||
                message.includes(query);

            const matchesStatus =
                statusFilter === "all" || status === statusFilter;

            return matchesSearch && matchesStatus;
        });
    }, [historyData, search, statusFilter]);

    const formatLabel = (value) => {
        if (!value) {
            return "Unknown";
        }

        return value.charAt(0).toUpperCase() + value.slice(1);
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
            time: date.toLocaleTimeString(),
        };
    };

    return (
        <>
            <Head title="Sync History" />

            <div className="zoho-page">
                {/* HEADER */}

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

                {/* CONTENT */}

                <main className="zoho-content">
                    <section className="page-intro">
                        <div>
                            <span className="eyebrow">ACTIVITY</span>

                            <h1>Sync History</h1>

                            <p>
                                View your previous Shopify to Zoho Books
                                synchronization activity.
                            </p>
                        </div>
                    </section>

                    <section className="history-card">
                        <div className="history-toolbar">
                            <div>
                                <h2>Synchronization Activity</h2>

                                <p>
                                    {filteredHistory.length}{" "}
                                    {filteredHistory.length === 1
                                        ? "record"
                                        : "records"}{" "}
                                    displayed
                                </p>
                            </div>

                            <div className="toolbar-actions">
                                <div className="search-wrapper">
                                    <span className="search-icon">⌕</span>

                                    <input
                                        type="text"
                                        className="search-box"
                                        placeholder="Search activity..."
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                    />
                                </div>

                                <select
                                    className="filter-select"
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
                        </div>

                        {filteredHistory.length === 0 ? (
                            <div className="empty-state">
                                <div className="empty-state-icon">↻</div>

                                <strong>
                                    {historyData.length === 0
                                        ? "No synchronization history"
                                        : "No matching activity"}
                                </strong>

                                <p>
                                    {historyData.length === 0
                                        ? "Your sync activity will appear here."
                                        : "Try changing your search or status filter."}
                                </p>
                            </div>
                        ) : (
                            <div className="table-wrapper">
                                <table className="history-table">
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
                                        {filteredHistory.map((history) => {
                                            const productTitle =
                                                history.product_variant?.product
                                                    ?.title ||
                                                "Unknown Product";

                                            const variantTitle =
                                                history.product_variant
                                                    ?.title ||
                                                "Unknown Variant";

                                            const status =
                                                history.status || "pending";

                                            const action =
                                                history.action || "unknown";

                                            const date = formatDate(
                                                history.created_at,
                                            );

                                            const zohoId =
                                                history.zoho_item_id ??
                                                history.product_variant
                                                    ?.zoho_item_id ??
                                                null;

                                            return (
                                                <tr key={history.id}>
                                                    <td>
                                                        <div className="product-cell">
                                                            <div className="product-avatar">
                                                                {productTitle
                                                                    .charAt(0)
                                                                    .toUpperCase()}
                                                            </div>

                                                            <div>
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

                                                    <td>
                                                        <span
                                                            className={`history-action ${action}`}
                                                        >
                                                            {formatLabel(
                                                                action,
                                                            )}
                                                        </span>
                                                    </td>

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

                                                    <td>
                                                        {zohoId ? (
                                                            <span className="zoho-id">
                                                                {zohoId}
                                                            </span>
                                                        ) : (
                                                            <span className="empty-id">
                                                                —
                                                            </span>
                                                        )}
                                                    </td>

                                                    <td>
                                                        <span
                                                            className={
                                                                status ===
                                                                "failed"
                                                                    ? "history-message failed-message"
                                                                    : "history-message"
                                                            }
                                                            title={
                                                                history.message ||
                                                                ""
                                                            }
                                                        >
                                                            {history.message ||
                                                                "No message"}
                                                        </span>
                                                    </td>

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
                </main>
            </div>
        </>
    );
}
