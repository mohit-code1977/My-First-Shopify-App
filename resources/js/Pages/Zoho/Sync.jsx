import React, { useMemo, useState } from "react";
import { Head } from "@inertiajs/react";

const SYNC_ALL_URL = "/zoho/sync-all";

const SYNC_VARIANT_URL = (variantId) => `/zoho/sync/${variantId}`;

export default function Sync({
    shop,
    variants = [],
    failedCount = 0,
    zohoConnected = false,
}) {
    const [search, setSearch] = useState("");

    const [statusFilter, setStatusFilter] = useState("all");

    const [syncingId, setSyncingId] = useState(null);

    const [syncingAll, setSyncingAll] = useState(false);

    /*
    |--------------------------------------------------------------------------
    | Dynamic statistics
    |--------------------------------------------------------------------------
    */

    const stats = useMemo(() => {
        const total = variants.length;

        const synced = variants.filter((variant) =>
            Boolean(variant.zoho_item_id ?? variant.zoho_id),
        ).length;

        const pending = total - synced;

        const failed = Number(failedCount || 0);

        const percentage = total > 0 ? Math.round((synced / total) * 100) : 0;

        return {
            total,
            synced,
            pending,
            failed,
            percentage,
        };
    }, [variants, failedCount]);

    /*
    |--------------------------------------------------------------------------
    | Search + filter
    |--------------------------------------------------------------------------
    */

    const filteredVariants = useMemo(() => {
        const query = search.trim().toLowerCase();

        return variants.filter((variant) => {
            const productTitle = variant.product?.title?.toLowerCase() || "";

            const variantTitle = variant.title?.toLowerCase() || "";

            const sku = variant.sku?.toLowerCase() || "";

            const zohoId = String(
                variant.zoho_item_id ?? variant.zoho_id ?? "",
            ).toLowerCase();

            const matchesSearch =
                !query ||
                productTitle.includes(query) ||
                variantTitle.includes(query) ||
                sku.includes(query) ||
                zohoId.includes(query);

            const isSynced = Boolean(variant.zoho_item_id ?? variant.zoho_id);

            const currentStatus = isSynced ? "synced" : "pending";

            const matchesStatus =
                statusFilter === "all" || currentStatus === statusFilter;

            return matchesSearch && matchesStatus;
        });
    }, [variants, search, statusFilter]);

    /*
    |--------------------------------------------------------------------------
    | Individual sync
    |--------------------------------------------------------------------------
    */

    const syncVariant = async (variant) => {
        if (!zohoConnected || !variant?.id || syncingId || syncingAll) {
            return;
        }

        setSyncingId(variant.id);

        try {
            const token = await shopify.idToken();

            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const response = await fetch(SYNC_VARIANT_URL(variant.id), {
                method: "POST",

                headers: {
                    Authorization: `Bearer ${token}`,
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                    "Content-Type": "application/json",
                },

                body: JSON.stringify({}),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || "Synchronization failed.");
            }

            console.log("Sync successful:", data);

            // Reload so product status / counts stay in sync with DB.
            window.location.reload();
        } catch (error) {
            console.error("Sync failed:", error);

            window.alert(error?.message || "Synchronization failed.");
        } finally {
            setSyncingId(null);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Sync all
    |--------------------------------------------------------------------------
    */

    const syncAll = async () => {
        if (!zohoConnected || syncingAll || syncingId) {
            return;
        }

        setSyncingAll(true);

        try {
            const token = await shopify.idToken();

            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const response = await fetch(SYNC_ALL_URL, {
                method: "POST",

                headers: {
                    Authorization: `Bearer ${token}`,
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                    "Content-Type": "application/json",
                },

                body: JSON.stringify({}),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || "Synchronization failed.");
            }

            console.log("Sync all successful:", data);

            window.location.reload();
        } catch (error) {
            console.error("Sync all failed:", error);

            window.alert(error?.message || "Synchronization failed.");
        } finally {
            setSyncingAll(false);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    const getZohoId = (variant) =>
        variant.zoho_item_id ?? variant.zoho_id ?? null;

    const isSynced = (variant) => Boolean(getZohoId(variant));

    const formatPrice = (price) => {
        const value = Number(price || 0);

        return `₹${value.toFixed(2)}`;
    };

    return (
        <>
            <Head title="Products" />

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
                    MAIN CONTENT
                ====================================================== */}

                <main className="zoho-content">
                    {/* =================================================
                        PAGE HEADER
                    ================================================== */}

                    <section className="page-intro">
                        <div className="page-intro-copy">
                            <span className="eyebrow">
                                PRODUCT SYNCHRONIZATION
                            </span>

                            <h1>Shopify Products</h1>

                            <p>
                                Manage and synchronize your Shopify products
                                with Zoho Books.
                            </p>
                        </div>

                        <button
                            type="button"
                            className="primary-btn"
                            onClick={syncAll}
                            disabled={syncingAll || !zohoConnected}
                        >
                            <span className="button-icon">↻</span>

                            {syncingAll ? "Syncing..." : "Sync All Products"}
                        </button>
                    </section>

                    {/* =================================================
                        SYNC OVERVIEW
                    ================================================== */}

                    <section className="overview-card">
                        <div className="overview-top">
                            <div>
                                <h2>Sync Overview</h2>

                                <p>Current synchronization status</p>
                            </div>

                            <div className="sync-percentage">
                                {stats.percentage}% synced
                            </div>
                        </div>

                        <div className="progress-track">
                            <div
                                className="progress-bar"
                                style={{
                                    width: `${stats.percentage}%`,
                                }}
                            />
                        </div>

                        <div className="overview-stats">
                            <div className="overview-stat">
                                <span>Total Products</span>

                                <strong>{stats.total}</strong>
                            </div>

                            <div className="overview-stat">
                                <span>Synced</span>

                                <strong className="success-value">
                                    {stats.synced}
                                </strong>
                            </div>

                            <div className="overview-stat">
                                <span>Pending</span>

                                <strong>{stats.pending}</strong>
                            </div>

                            <div className="overview-stat">
                                <span>Failed</span>

                                <strong className="danger-value">
                                    {stats.failed}
                                </strong>
                            </div>
                        </div>
                    </section>

                    {/* =================================================
                        PRODUCT TABLE
                    ================================================== */}

                    <section className="products-card">
                        <div className="products-toolbar">
                            <div>
                                <h2>Shopify Products</h2>

                                <p>
                                    {filteredVariants.length}{" "}
                                    {filteredVariants.length === 1
                                        ? "product"
                                        : "products"}{" "}
                                    displayed
                                </p>
                            </div>

                            <div className="toolbar-actions">
                                <div className="search-wrapper">
                                    <span className="search-icon">⌕</span>

                                    <input
                                        type="text"
                                        className="search-box"
                                        placeholder="Search products..."
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

                                    <option value="synced">Synced</option>

                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                        </div>

                        {filteredVariants.length > 0 ? (
                            <div className="table-wrapper">
                                <table className="products-table">
                                    <thead>
                                        <tr>
                                            <th>PRODUCT</th>

                                            <th>SKU</th>

                                            <th>PRICE</th>

                                            <th>INVENTORY</th>

                                            <th>ZOHO ITEM</th>

                                            <th>STATUS</th>

                                            <th>ACTION</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {filteredVariants.map((variant) => {
                                            const synced = isSynced(variant);

                                            const zohoId = getZohoId(variant);

                                            const isSyncing =
                                                syncingId === variant.id;

                                            const productTitle =
                                                variant.product?.title ||
                                                "Unknown Product";

                                            return (
                                                <tr key={variant.id}>
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
                                                                    {variant.title ||
                                                                        "Default Variant"}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <span className="sku">
                                                            {variant.sku || "—"}
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <span className="price">
                                                            {formatPrice(
                                                                variant.price,
                                                            )}
                                                        </span>
                                                    </td>

                                                    <td>
                                                        {variant.inventory_quantity ??
                                                            0}
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
                                                                synced
                                                                    ? "status synced"
                                                                    : "status pending"
                                                            }
                                                        >
                                                            <span className="status-dot" />

                                                            {synced
                                                                ? "Synced"
                                                                : "Pending"}
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <button
                                                            type="button"
                                                            className="action-btn"
                                                            disabled={
                                                                Boolean(
                                                                    syncingId,
                                                                ) ||
                                                                syncingAll ||
                                                                !zohoConnected
                                                            }
                                                            onClick={() =>
                                                                syncVariant(
                                                                    variant,
                                                                )
                                                            }
                                                        >
                                                            {isSyncing
                                                                ? "Syncing..."
                                                                : synced
                                                                  ? "Sync Again"
                                                                  : "Sync Now"}
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="empty-state">
                                <div className="empty-state-icon">⌕</div>

                                <strong>No products found</strong>

                                <p>Try changing your search or filter.</p>
                            </div>
                        )}
                    </section>
                </main>
            </div>
        </>
    );
}
