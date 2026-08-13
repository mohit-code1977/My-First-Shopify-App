import React, { useEffect, useMemo, useState } from "react";
import { Head } from "@inertiajs/react";

const SYNC_ALL_URL = "/zoho/sync-all";
const SYNC_VARIANT_URL = "/zoho/sync";
const SYNC_DATA_URL = "/api/zoho/sync";

export default function Sync({
    shop,
    variants = [],
    failedCount = 0,
    zohoConnected = false,
}) {
    const [loading, setLoading] = useState(true);
    const [shopData, setShopData] = useState(shop || {});
    const [variantsData, setVariantsData] = useState(variants || []);
    const [connectedState, setConnectedState] = useState(zohoConnected);

    const [search, setSearch] = useState("");
    const [direction, setDirection] = useState("shopify-to-zoho");
    const [connectionFilter, setConnectionFilter] = useState("all");

    const [syncingId, setSyncingId] = useState(null);
    const [syncingAll, setSyncingAll] = useState(false);

    const [selectedIds, setSelectedIds] = useState([]);

    const loadData = async () => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(SYNC_DATA_URL, { headers });
            const data = await response.json();
            if (response.ok && data.success) {
                if (data.variants) setVariantsData(data.variants);
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean") {
                    setConnectedState(data.zohoConnected);
                }
            }
        } catch (error) {
            console.error("Failed to load sync data:", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    const getZohoId = (variant) =>
        variant?.zoho_item_id ?? variant?.zoho_id ?? null;

    const getShopifyVariantId = (variant) =>
        variant?.shopify_variant_id ?? variant?.id ?? null;

    const isSynced = (variant) => Boolean(getZohoId(variant));

    const getProductTitle = (variant) =>
        variant?.product?.title || "Unknown Product";

    const getVariantTitle = (variant) => variant?.title || "Default Title";

    const getProductImage = (variant) => {
        return (
            variant?.image_url ||
            variant?.image?.src ||
            variant?.product?.image?.src ||
            variant?.product?.image_url ||
            null
        );
    };

    const getCurrencyCode = (variant) =>
        variant?.currency_code ||
        variant?.currency ||
        variant?.product?.currency_code ||
        shopData?.currency_code ||
        shopData?.currency ||
        null;

    const formatPrice = (price, variant) => {
        const value = Number(price);

        if (!Number.isFinite(value)) {
            return "—";
        }

        const currencyCode = getCurrencyCode(variant);

        if (!currencyCode) {
            return value.toFixed(2);
        }

        try {
            return new Intl.NumberFormat(undefined, {
                style: "currency",
                currency: currencyCode,
            }).format(value);
        } catch {
            return value.toFixed(2);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    */

    const filteredVariants = useMemo(() => {
        const query = search.trim().toLowerCase();

        return (variantsData || []).filter((variant) => {
            const productTitle = getProductTitle(variant).toLowerCase();
            const variantTitle = getVariantTitle(variant).toLowerCase();
            const sku = String(variant?.sku || "").toLowerCase();
            const zohoId = String(getZohoId(variant) || "").toLowerCase();

            const matchesSearch =
                !query ||
                productTitle.includes(query) ||
                variantTitle.includes(query) ||
                sku.includes(query) ||
                zohoId.includes(query);

            const synced = isSynced(variant);

            const matchesConnection =
                connectionFilter === "all" ||
                (connectionFilter === "connected" && synced) ||
                (connectionFilter === "not-connected" && !synced);

            return matchesSearch && matchesConnection;
        });
    }, [variantsData, search, connectionFilter]);

    /*
    |--------------------------------------------------------------------------
    | Selection
    |--------------------------------------------------------------------------
    */

    const allVisibleSelected =
        filteredVariants.length > 0 &&
        filteredVariants.every((variant) => selectedIds.includes(variant.id));

    const toggleSelectAll = () => {
        if (allVisibleSelected) {
            setSelectedIds((current) =>
                current.filter(
                    (id) =>
                        !filteredVariants.some((variant) => variant.id === id),
                ),
            );

            return;
        }

        setSelectedIds((current) => {
            const next = new Set(current);

            filteredVariants.forEach((variant) => {
                next.add(variant.id);
            });

            return Array.from(next);
        });
    };

    const toggleSelected = (variantId) => {
        setSelectedIds((current) =>
            current.includes(variantId)
                ? current.filter((id) => id !== variantId)
                : [...current, variantId],
        );
    };

    /*
|--------------------------------------------------------------------------
| Sync Selected
|--------------------------------------------------------------------------
*/

    const syncSelected = async () => {
        if (
            selectedIds.length === 0 ||
            !connectedState ||
            syncingAll ||
            syncingId
        ) {
            return;
        }

        setSyncingAll(true);

        const selectedVariants = (variantsData || []).filter((variant) =>
            selectedIds.includes(variant.id),
        );

        let successCount = 0;
        let failedCountLocal = 0;
        const failures = [];

        try {
            const token = await window.shopify?.idToken();

            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const headers = {
                Authorization: token ? `Bearer ${token}` : "",
                "X-CSRF-TOKEN": csrfToken,
                Accept: "application/json",
                "Content-Type": "application/json",
            };

            for (const variant of selectedVariants) {
                try {
                    const shopifyVariantId = getShopifyVariantId(variant);

                    const response = await fetch(SYNC_VARIANT_URL, {
                        method: "POST",

                        headers,

                        body: JSON.stringify({
                            shopify_variant_id: shopifyVariantId,
                        }),
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(
                            data.message || "Synchronization failed.",
                        );
                    }

                    successCount++;
                } catch (error) {
                    failedCountLocal++;

                    failures.push(
                        `${getProductTitle(variant)}: ${
                            error?.message || "Synchronization failed"
                        }`,
                    );
                }
            }

            setSelectedIds([]);

            if (failedCountLocal > 0) {
                window.alert(
                    `Sync completed.\n\n` +
                        `Successful: ${successCount}\n` +
                        `Failed: ${failedCountLocal}\n\n` +
                        failures.join("\n"),
                );
            }

            await loadData();
        } catch (error) {
            console.error("Selected sync failed:", error);

            window.alert(
                error?.message || "Selected products synchronization failed.",
            );
        } finally {
            setSyncingAll(false);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Individual Sync
    |--------------------------------------------------------------------------
    */

    const syncVariant = async (variant) => {
        if (!connectedState || !variant?.id || syncingId || syncingAll) {
            return;
        }

        setSyncingId(variant.id);

        try {
            const token = await window.shopify?.idToken();

            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const shopifyVariantId = getShopifyVariantId(variant);

            const response = await fetch(SYNC_VARIANT_URL, {
                method: "POST",

                headers: {
                    Authorization: token ? `Bearer ${token}` : "",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                    "Content-Type": "application/json",
                },

                body: JSON.stringify({
                    shopify_variant_id: shopifyVariantId,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || "Synchronization failed.");
            }

            await loadData();
        } catch (error) {
            console.error("Sync failed:", error);

            window.alert(error?.message || "Synchronization failed.");
        } finally {
            setSyncingId(null);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Sync All
    |--------------------------------------------------------------------------
    */

    const syncAll = async () => {
        if (!connectedState || syncingAll || syncingId) {
            return;
        }

        setSyncingAll(true);

        try {
            const token = await window.shopify?.idToken();

            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const response = await fetch(SYNC_ALL_URL, {
                method: "POST",
                headers: {
                    Authorization: token ? `Bearer ${token}` : "",
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

            await loadData();
        } catch (error) {
            console.error("Sync all failed:", error);

            window.alert(error?.message || "Synchronization failed.");
        } finally {
            setSyncingAll(false);
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Refresh
    |--------------------------------------------------------------------------
    */

    const refreshPage = () => {
        loadData();
    };

    /*
    |--------------------------------------------------------------------------
    | Zoho → Shopify
    |--------------------------------------------------------------------------
    |
    | The current backend only exposes Shopify → Zoho sync endpoints.
    | The second tab is therefore kept as the reference UI state, but
    | doesn't pretend that reverse synchronization already exists.
    */

    const reverseSyncAvailable = false;

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <>
            <Head title="Products" />

            <div className="zoho-products-page">
                {/* =========================================================
                    APP HEADER
                ========================================================== */}

                <header className="zoho-products-header">
                    <div className="zoho-products-header-left">
                        <div className="zoho-products-logo">Z</div>

                        <div>
                            <div className="zoho-products-header-title">
                                Zoho Books Integration
                            </div>

                            <div className="zoho-products-header-subtitle">
                                Shopify Store:{" "}
                                {shopData?.shop_domain || "Unknown store"}
                            </div>
                        </div>
                    </div>

                    <div
                        className={
                            connectedState
                                ? "zoho-connection-status connected"
                                : "zoho-connection-status disconnected"
                        }
                    >
                        <span className="zoho-connection-dot" />

                        {connectedState ? "Connected" : "Not Connected"}
                    </div>
                </header>

                {/* =========================================================
                    PAGE
                ========================================================== */}

                <main className="zoho-products-content">
                    <section className="reference-products-card">
                        {/* =================================================
                            CARD HEADER
                        ================================================== */}

                        <div className="reference-products-card-header">
                            <div>
                                <h1>Products</h1>
                            </div>

                            <button
                                type="button"
                                className="reference-refresh-btn"
                                onClick={refreshPage}
                            >
                                ↻<span>Refresh</span>
                            </button>
                        </div>

                        {/* =================================================
                            DIRECTION TABS
                        ================================================== */}

                        <div className="sync-direction-tabs">
                            <button
                                type="button"
                                className={
                                    direction === "shopify-to-zoho"
                                        ? "sync-direction-tab active"
                                        : "sync-direction-tab"
                                }
                                onClick={() => setDirection("shopify-to-zoho")}
                            >
                                Shopify → Zoho
                            </button>

                            <button
                                type="button"
                                className={
                                    direction === "zoho-to-shopify"
                                        ? "sync-direction-tab active"
                                        : "sync-direction-tab"
                                }
                                onClick={() => setDirection("zoho-to-shopify")}
                                disabled={!reverseSyncAvailable}
                                title={
                                    reverseSyncAvailable
                                        ? ""
                                        : "Zoho → Shopify sync is not available yet."
                                }
                            >
                                Zoho → Shopify
                            </button>
                        </div>

                        {/* =================================================
                            TOOLBAR
                        ================================================== */}

                        <div className="reference-products-toolbar">
                            <div className="reference-search-wrap">
                                <span className="reference-search-icon">⌕</span>

                                <input
                                    type="text"
                                    className="reference-search-input"
                                    placeholder="Search products, variants, SKU..."
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </div>

                            <div className="reference-toolbar-right">
                                <button
                                    type="button"
                                    className="reference-small-btn"
                                    onClick={refreshPage}
                                >
                                    Refresh
                                </button>

                                <button
                                    type="button"
                                    className="reference-sync-selected-btn"
                                    disabled={
                                        selectedIds.length === 0 ||
                                        !connectedState ||
                                        direction !== "shopify-to-zoho" ||
                                        syncingAll ||
                                        Boolean(syncingId)
                                    }
                                    onClick={syncSelected}
                                >
                                    {syncingAll
                                        ? `Syncing ${selectedIds.length}...`
                                        : selectedIds.length > 0
                                          ? `Sync Selected (${selectedIds.length})`
                                          : "Sync Selected"}
                                </button>
                            </div>
                        </div>

                        {/* =================================================
                            CONNECTION FILTERS
                        ================================================== */}

                        <div className="reference-connection-tabs">
                            <button
                                type="button"
                                className={
                                    connectionFilter === "all"
                                        ? "reference-connection-tab active"
                                        : "reference-connection-tab"
                                }
                                onClick={() => setConnectionFilter("all")}
                            >
                                All
                            </button>

                            <button
                                type="button"
                                className={
                                    connectionFilter === "connected"
                                        ? "reference-connection-tab active"
                                        : "reference-connection-tab"
                                }
                                onClick={() => setConnectionFilter("connected")}
                            >
                                Connected to Zoho (Linked)
                            </button>

                            <button
                                type="button"
                                className={
                                    connectionFilter === "not-connected"
                                        ? "reference-connection-tab active"
                                        : "reference-connection-tab"
                                }
                                onClick={() =>
                                    setConnectionFilter("not-connected")
                                }
                            >
                                Not Connected to Zoho (Not Linked)
                            </button>
                        </div>

                        {/* =================================================
                            TABLE
                        ================================================== */}

                        {direction === "zoho-to-shopify" ? (
                            <div className="reference-reverse-sync-placeholder">
                                <div className="reference-placeholder-icon">
                                    Z
                                </div>

                                <h2>Zoho → Shopify</h2>

                                <p>
                                    Reverse product synchronization is not
                                    available in the current backend yet.
                                </p>
                            </div>
                        ) : filteredVariants.length > 0 ? (
                            <div className="reference-table-wrapper">
                                <table className="reference-products-table">
                                    <thead>
                                        <tr>
                                            <th className="check-column">
                                                <input
                                                    type="checkbox"
                                                    checked={allVisibleSelected}
                                                    onChange={toggleSelectAll}
                                                    aria-label="Select all products"
                                                />
                                            </th>

                                            <th>IMAGE</th>
                                            <th>VARIANT</th>
                                            <th>SKU</th>
                                            <th>PRICE</th>
                                            <th>STATUS</th>
                                            <th>INVENTORY</th>
                                            <th>ACTIONS</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {filteredVariants.map((variant) => {
                                            const synced = isSynced(variant);

                                            const image =
                                                getProductImage(variant);

                                            const isSyncing =
                                                syncingId === variant.id;

                                            const isSelected =
                                                selectedIds.includes(
                                                    variant.id,
                                                );

                                            return (
                                                <tr key={variant.id}>
                                                    <td className="check-column">
                                                        <input
                                                            type="checkbox"
                                                            checked={isSelected}
                                                            onChange={() =>
                                                                toggleSelected(
                                                                    variant.id,
                                                                )
                                                            }
                                                            aria-label={`Select ${getProductTitle(
                                                                variant,
                                                            )}`}
                                                        />
                                                    </td>

                                                    <td>
                                                        <div className="reference-product-image">
                                                            {image ? (
                                                                <img
                                                                    src={image}
                                                                    alt=""
                                                                />
                                                            ) : (
                                                                getProductTitle(
                                                                    variant,
                                                                )
                                                                    .charAt(0)
                                                                    .toUpperCase()
                                                            )}
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <div className="reference-variant-cell">
                                                            <div className="reference-product-title">
                                                                {getProductTitle(
                                                                    variant,
                                                                )}
                                                            </div>

                                                            <div className="reference-variant-title">
                                                                {getVariantTitle(
                                                                    variant,
                                                                )}
                                                            </div>
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <span className="reference-sku">
                                                            {variant.sku || "—"}
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <span className="reference-price">
                                                            {formatPrice(
                                                                variant.price,
                                                                variant,
                                                            )}
                                                        </span>
                                                    </td>

                                                    <td>
                                                        {synced ? (
                                                            <span className="reference-status active">
                                                                Synced
                                                            </span>
                                                        ) : (
                                                            <span className="reference-status not-linked">
                                                                Not Linked
                                                            </span>
                                                        )}
                                                    </td>

                                                    <td>
                                                        <span className="reference-inventory">
                                                            {variant.inventory_quantity ??
                                                                0}
                                                        </span>
                                                    </td>

                                                    <td>
                                                        <div className="reference-action-group">
                                                            <button
                                                                type="button"
                                                                className="reference-sync-btn"
                                                                disabled={
                                                                    !connectedState ||
                                                                    syncingAll ||
                                                                    Boolean(
                                                                        syncingId,
                                                                    )
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
                                                                      : "Sync"}
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="reference-empty-state">
                                <div className="reference-empty-icon">⌕</div>

                                <h2>No products found</h2>

                                <p>
                                    Try changing your search or connection
                                    filter.
                                </p>
                            </div>
                        )}

                        {/* =================================================
                            FOOTER
                        ================================================== */}

                        <div className="reference-products-footer">
                            <span>{filteredVariants.length} products</span>

                            <span>
                                {failedCount > 0
                                    ? `${failedCount} failed synchronization${failedCount === 1 ? "" : "s"}`
                                    : "Sync status is up to date"}
                            </span>
                        </div>
                    </section>
                </main>
            </div>
        </>
    );
}
