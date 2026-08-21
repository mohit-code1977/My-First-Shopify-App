import React, { useEffect, useState } from "react";
import { Banner } from "@shopify/polaris";
import ZohoLayout from "@/Layouts/ZohoLayout";

const DATA_URL = "/api/zoho/sync";
const SINGLE_SYNC_URL = "/zoho/sync";
const BULK_SYNC_URL = "/zoho/sync-all";

export default function Products({ shop, variants = [], failedCount = 0, zohoConnected = false, host = "" }) {
    const [loading, setLoading] = useState(true);
    const [syncingVariantId, setSyncingVariantId] = useState(null);
    const [bulkSyncing, setBulkSyncing] = useState(false);
    const [search, setSearch] = useState("");
    const [filterStatus, setFilterStatus] = useState("all");
    const [selectedVariantIds, setSelectedVariantIds] = useState([]);
    const [variantList, setVariantList] = useState(variants || []);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [notification, setNotification] = useState(null);

    const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const loadData = async () => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(DATA_URL, { headers });
            const data = await response.json();

            if (response.ok && data.success) {
                setVariantList(data.variants || []);
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean") setConnectedState(data.zohoConnected);
            }
        } catch (error) {
            console.error("Failed to fetch product catalog:", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    const handleSingleSync = async (variantId) => {
        if (!connectedState) {
            setNotification({ type: "error", message: "Please connect your Zoho Books account in Settings first." });
            return;
        }

        setSyncingVariantId(variantId);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SINGLE_SYNC_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ shopify_variant_id: variantId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({ type: "success", message: data.message || "Product variant synchronized successfully." });
                await loadData();
            } else {
                setNotification({ type: "error", message: data.message || "Product variant sync failed." });
            }
        } catch (error) {
            setNotification({ type: "error", message: "Network error during sync." });
        } finally {
            setSyncingVariantId(null);
        }
    };

    const handleBulkSync = async () => {
        if (!connectedState) {
            setNotification({ type: "error", message: "Please connect your Zoho Books account in Settings first." });
            return;
        }

        setBulkSyncing(true);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(BULK_SYNC_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    Authorization: token ? `Bearer ${token}` : "",
                },
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: `Bulk sync finished! Synced: ${data.data?.success || 0}, Failed: ${data.data?.failed || 0}`,
                });
                await loadData();
            } else {
                setNotification({ type: "error", message: data.message || "Bulk sync failed." });
            }
        } catch (error) {
            setNotification({ type: "error", message: "Network error during bulk sync." });
        } finally {
            setBulkSyncing(false);
        }
    };

    const formatCurrency = (amount, currencyCode = "USD") => {
        const val = parseFloat(amount || 0);
        const code = (currencyCode || "USD").toUpperCase();
        try {
            return new Intl.NumberFormat("en-US", {
                style: "currency",
                currency: code,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(val);
        } catch (e) {
            const symbols = { INR: "₹", USD: "$", EUR: "€", GBP: "£" };
            const symbol = symbols[code] || `${code} `;
            return `${symbol}${val.toFixed(2)}`;
        }
    };

    const filteredVariants = variantList.filter((v) => {
        const titleMatch = (v.product?.title || "").toLowerCase().includes(search.toLowerCase()) ||
            (v.title || "").toLowerCase().includes(search.toLowerCase()) ||
            (v.sku || "").toLowerCase().includes(search.toLowerCase());

        if (!titleMatch) return false;

        if (filterStatus === "connected") return !!v.zoho_item_id;
        if (filterStatus === "not_connected") return !v.zoho_item_id;
        return true;
    });

    const toggleSelectAll = () => {
        if (selectedVariantIds.length === filteredVariants.length) {
            setSelectedVariantIds([]);
        } else {
            setSelectedVariantIds(filteredVariants.map((v) => v.shopify_variant_id));
        }
    };

    const toggleSelectOne = (id) => {
        setSelectedVariantIds((prev) =>
            prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
        );
    };

    return (
        <ZohoLayout
            title="Products | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="products"
        >
            <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
                {/* NOTIFICATION */}
                {notification && (
                    <Banner
                        tone={notification.type === "success" ? "success" : notification.type === "warning" ? "warning" : "critical"}
                        onDismiss={() => setNotification(null)}
                    >
                        <p>{notification.message}</p>
                    </Banner>
                )}

                {/* HEADER & TOP ACTIONS */}
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                    <div>
                        <h1 style={{ fontSize: "24px", fontWeight: 700, color: "#1a1d20", margin: 0 }}>
                            Products
                        </h1>
                        <p style={{ fontSize: "14px", color: "#616a75", margin: "4px 0 0 0" }}>
                            Manage Shopify products and their synchronization mapping to Zoho Books items.
                        </p>
                    </div>

                    <div style={{ display: "flex", gap: "10px" }}>
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

                        <button
                            type="button"
                            onClick={handleBulkSync}
                            disabled={bulkSyncing || loading}
                            style={{
                                padding: "8px 16px",
                                borderRadius: "6px",
                                border: "none",
                                backgroundColor: "#005bd3",
                                fontSize: "13px",
                                fontWeight: 600,
                                color: "#ffffff",
                                cursor: bulkSyncing ? "wait" : "pointer",
                            }}
                        >
                            {bulkSyncing ? "Syncing All Products..." : "⚡ Sync All Products"}
                        </button>
                    </div>
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
                    {/* FILTER TABS */}
                    <div style={{ display: "flex", gap: "8px" }}>
                        {[
                            { key: "all", label: `All (${variantList.length})` },
                            { key: "connected", label: `Connected (${variantList.filter((v) => !!v.zoho_item_id).length})` },
                            { key: "not_connected", label: `Not Connected (${variantList.filter((v) => !v.zoho_item_id).length})` },
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
                                    backgroundColor: filterStatus === tab.key ? "#202223" : "#f1f2f4",
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
                        placeholder="Search products, variants, SKU..."
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

                {/* PRODUCTS TABLE */}
                <div style={{ backgroundColor: "#ffffff", borderRadius: "10px", border: "1px solid #e1e3e5", overflow: "hidden" }}>
                    <table style={{ width: "100%", borderCollapse: "collapse", fontSize: "13px" }}>
                        <thead>
                            <tr style={{ backgroundColor: "#f8f9fa", borderBottom: "1px solid #e1e3e5", textAlign: "left", color: "#616a75" }}>
                                <th style={{ padding: "12px 16px", width: "40px" }}>
                                    <input
                                        type="checkbox"
                                        checked={filteredVariants.length > 0 && selectedVariantIds.length === filteredVariants.length}
                                        onChange={toggleSelectAll}
                                    />
                                </th>
                                <th style={{ padding: "12px 16px" }}>PRODUCT / VARIANT</th>
                                <th style={{ padding: "12px 16px" }}>SKU</th>
                                <th style={{ padding: "12px 16px" }}>PRICE</th>
                                <th style={{ padding: "12px 16px" }}>INVENTORY</th>
                                <th style={{ padding: "12px 16px" }}>ZOHO STATUS</th>
                                <th style={{ padding: "12px 16px", textAlign: "right" }}>ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: "center", padding: "40px", color: "#616a75" }}>
                                        Loading products catalog...
                                    </td>
                                </tr>
                            ) : filteredVariants.length > 0 ? (
                                filteredVariants.map((v) => {
                                    const isSelected = selectedVariantIds.includes(v.shopify_variant_id);
                                    const isSyncing = syncingVariantId === v.shopify_variant_id;
                                    const isLinked = !!v.zoho_item_id;

                                    const rawProductId = v.product?.shopify_product_id || v.product?.id || v.shopify_product_id;
                                    const numProductId = rawProductId ? String(rawProductId).replace(/[^0-9]/g, "") : null;
                                    const shopDomainClean = (shopData?.shop_domain || shop?.shop_domain || "").replace(/^https?:\/\//, "").replace(/\/$/, "");
                                    const productAdminUrl = (shopDomainClean && numProductId)
                                        ? `https://${shopDomainClean}/admin/products/${numProductId}`
                                        : null;
                                    const productTitle = v.product?.title || "Untitled Product";

                                    return (
                                        <tr key={v.shopify_variant_id} style={{ borderBottom: "1px solid #f1f2f4", backgroundColor: isSelected ? "#f4f8ff" : "transparent" }}>
                                            <td style={{ padding: "12px 16px" }}>
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => toggleSelectOne(v.shopify_variant_id)}
                                                />
                                            </td>
                                            <td style={{ padding: "12px 16px" }}>
                                                <div style={{ display: "flex", alignItems: "center", gap: "12px" }}>
                                                    {v.product?.image_url ? (
                                                        <img
                                                            src={v.product.image_url}
                                                            alt={productTitle}
                                                            style={{ width: "36px", height: "36px", borderRadius: "6px", objectFit: "cover", border: "1px solid #e1e3e5" }}
                                                        />
                                                    ) : (
                                                        <div style={{ width: "36px", height: "36px", borderRadius: "6px", backgroundColor: "#f1f2f4", display: "flex", alignItems: "center", justifyContent: "center", color: "#8c9196", fontSize: "16px" }}>
                                                            📦
                                                        </div>
                                                    )}
                                                    <div>
                                                        {productAdminUrl ? (
                                                            <a
                                                                href={productAdminUrl}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                style={{
                                                                    fontWeight: 600,
                                                                    color: "#005bd3",
                                                                    textDecoration: "none",
                                                                }}
                                                                onMouseEnter={(e) => (e.currentTarget.style.textDecoration = "underline")}
                                                                onMouseLeave={(e) => (e.currentTarget.style.textDecoration = "none")}
                                                            >
                                                                {productTitle}
                                                            </a>
                                                        ) : (
                                                            <div style={{ fontWeight: 600, color: "#1a1d20" }}>
                                                                {productTitle}
                                                            </div>
                                                        )}
                                                        {v.title && v.title !== "Default Title" && (
                                                            productAdminUrl ? (
                                                                <a
                                                                    href={productAdminUrl}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    style={{
                                                                        fontSize: "12px",
                                                                        color: "#005bd3",
                                                                        marginTop: "2px",
                                                                        display: "block",
                                                                        textDecoration: "none",
                                                                    }}
                                                                    onMouseEnter={(e) => (e.currentTarget.style.textDecoration = "underline")}
                                                                    onMouseLeave={(e) => (e.currentTarget.style.textDecoration = "none")}
                                                                >
                                                                    {v.title}
                                                                </a>
                                                            ) : (
                                                                <div style={{ fontSize: "12px", color: "#616a75", marginTop: "2px" }}>
                                                                    {v.title}
                                                                </div>
                                                            )
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td style={{ padding: "12px 16px", color: "#616a75", fontFamily: "monospace" }}>
                                                {v.sku || "—"}
                                            </td>
                                            <td style={{ padding: "12px 16px", fontWeight: 600 }}>
                                                {formatCurrency(v.price, v.currency || "USD")}
                                            </td>
                                            <td style={{ padding: "12px 16px", color: "#202223" }}>
                                                {v.inventory_quantity ?? 0} in stock
                                            </td>
                                            <td style={{ padding: "12px 16px" }}>
                                                <span
                                                    style={{
                                                        padding: "3px 10px",
                                                        borderRadius: "12px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        backgroundColor: isLinked ? "#eafbdf" : "#fff8e6",
                                                        color: isLinked ? "#108043" : "#b78103",
                                                        border: isLinked ? "1px solid #b7eb8f" : "1px solid #ffe58f",
                                                    }}
                                                >
                                                    {isLinked ? `Synced (ID: ${v.zoho_item_id})` : "Not Linked"}
                                                </span>
                                            </td>
                                            <td style={{ padding: "12px 16px", textAlign: "right" }}>
                                                <button
                                                    type="button"
                                                    onClick={() => handleSingleSync(v.shopify_variant_id)}
                                                    disabled={isSyncing}
                                                    style={{
                                                        padding: "6px 14px",
                                                        borderRadius: "6px",
                                                        border: "1px solid #c9cccf",
                                                        backgroundColor: "#ffffff",
                                                        fontSize: "12px",
                                                        fontWeight: 600,
                                                        color: "#202223",
                                                        cursor: isSyncing ? "wait" : "pointer",
                                                    }}
                                                >
                                                    {isSyncing ? "Syncing..." : isLinked ? "Sync Again" : "Sync Now"}
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan={7} style={{ textAlign: "center", padding: "40px", color: "#616a75" }}>
                                        No products found matching your search.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </ZohoLayout>
    );
}
