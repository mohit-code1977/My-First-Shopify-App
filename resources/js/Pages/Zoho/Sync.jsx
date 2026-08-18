import React, { useEffect, useState } from "react";
import { Banner } from "@shopify/polaris";
import ZohoLayout from "@/Layouts/ZohoLayout";

const DATA_URL = "/api/zoho/sync";
const BULK_SYNC_URL = "/zoho/sync-all";
const INVENTORY_SYNC_URL = "/zoho/sync-inventory";

export default function Sync({ shop, zohoConnected = false, host = "", inventoryCapability = "unavailable" }) {
    const [loading, setLoading] = useState(true);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [capabilityState, setCapabilityState] = useState(inventoryCapability);
    const [direction, setDirection] = useState("shopify_to_zoho");
    const [syncingType, setSyncingType] = useState(null);
    const [notification, setNotification] = useState(null);

    const loadData = async () => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(DATA_URL, { headers });
            const data = await response.json();

            if (response.ok && data.success) {
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean") setConnectedState(data.zohoConnected);
                if (data.inventoryCapability) setCapabilityState(data.inventoryCapability);
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

    const handleBulkProductSync = async () => {
        if (!connectedState) {
            setNotification({ type: "error", message: "Zoho is not connected. Please connect in Settings first." });
            return;
        }

        setSyncingType("products");
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(BULK_SYNC_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: `Product sync completed! Synced: ${data.data?.success || 0}, Failed: ${data.data?.failed || 0}`,
                });
            } else {
                setNotification({ type: "error", message: data.message || "Product sync failed." });
            }
        } catch (error) {
            setNotification({ type: "error", message: "Network error during product sync." });
        } finally {
            setSyncingType(null);
        }
    };

    const handleInventorySync = async () => {
        if (!connectedState) {
            setNotification({ type: "error", message: "Zoho is not connected. Please connect in Settings first." });
            return;
        }

        setSyncingType("inventory");
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(INVENTORY_SYNC_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
            });

            const data = await response.json();

            if (response.ok && data.success) {
                const synced = data.synced ?? data.data?.synced ?? 0;
                const failed = data.failed ?? data.data?.failed ?? 0;
                const skipped = data.skipped ?? data.data?.skipped ?? 0;
                const isWarning = skipped > 0 && synced === 0;
                setNotification({
                    type: isWarning ? "warning" : "success",
                    message: `Bulk Zoho inventory sync complete! Synced: ${synced}, Failed: ${failed}, Skipped: ${skipped}`,
                });
            } else {
                const isWarning = data.sync_available === false || data.item_inventory_tracked === false;
                setNotification({
                    type: isWarning ? "warning" : "error",
                    message: data.reason || data.message || "Inventory sync failed.",
                });
            }
        } catch (error) {
            setNotification({ type: "error", message: "Network error during inventory sync." });
        } finally {
            setSyncingType(null);
        }
    };

    return (
        <ZohoLayout
            title="Sync Operations | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="sync"
        >
            <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
                {notification && (
                    <Banner
                        tone={notification.type === "success" ? "success" : notification.type === "warning" ? "warning" : "critical"}
                        onDismiss={() => setNotification(null)}
                    >
                        <p>{notification.message}</p>
                    </Banner>
                )}

                {/* HEADER */}
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                    <div>
                        <h1 style={{ fontSize: "24px", fontWeight: 700, color: "#1a1d20", margin: 0 }}>
                            Synchronization Center
                        </h1>
                        <p style={{ fontSize: "14px", color: "#616a75", margin: "4px 0 0 0" }}>
                            Trigger manual synchronization tasks and manage bidirectional data flows.
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

                {/* DIRECTION TOGGLE BUTTONS */}
                <div style={{ display: "flex", gap: "12px" }}>
                    <button
                        type="button"
                        onClick={() => setDirection("shopify_to_zoho")}
                        style={{
                            padding: "10px 20px",
                            borderRadius: "8px",
                            border: "none",
                            fontSize: "14px",
                            fontWeight: 600,
                            backgroundColor: direction === "shopify_to_zoho" ? "#005bd3" : "#f1f2f4",
                            color: direction === "shopify_to_zoho" ? "#ffffff" : "#616a75",
                            cursor: "pointer",
                        }}
                    >
                        Shopify → Zoho Books
                    </button>

                    <button
                        type="button"
                        onClick={() => setDirection("zoho_to_shopify")}
                        style={{
                            padding: "10px 20px",
                            borderRadius: "8px",
                            border: "none",
                            fontSize: "14px",
                            fontWeight: 600,
                            backgroundColor: direction === "zoho_to_shopify" ? "#005bd3" : "#f1f2f4",
                            color: direction === "zoho_to_shopify" ? "#ffffff" : "#616a75",
                            cursor: "pointer",
                        }}
                    >
                        Zoho Books → Shopify
                    </button>
                </div>

                {/* SYNC CARDS CONTAINER */}
                {direction === "shopify_to_zoho" ? (
                    <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "20px" }}>
                        {/* PRODUCT SYNC CARD */}
                        <div style={{ backgroundColor: "#ffffff", borderRadius: "10px", padding: "24px", border: "1px solid #e1e3e5", boxShadow: "0 1px 2px rgba(0,0,0,0.05)" }}>
                            <div style={{ fontSize: "32px", marginBottom: "12px" }}>📦</div>
                            <h3 style={{ fontSize: "16px", fontWeight: 700, color: "#1a1d20", margin: 0 }}>
                                Products & Variants
                            </h3>
                            <p style={{ fontSize: "13px", color: "#616a75", margin: "8px 0 20px 0", lineHeight: "1.4" }}>
                                Synchronize Shopify products, variants, SKUs, and prices to Zoho Books item catalog.
                            </p>
                            <button
                                type="button"
                                onClick={handleBulkProductSync}
                                disabled={syncingType === "products"}
                                style={{
                                    width: "100%",
                                    padding: "10px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#005bd3",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: syncingType === "products" ? "wait" : "pointer",
                                }}
                            >
                                {syncingType === "products" ? "Syncing Products..." : "Run Product Sync"}
                            </button>
                        </div>

                        {/* CUSTOMER SYNC CARD */}
                        <div style={{ backgroundColor: "#ffffff", borderRadius: "10px", padding: "24px", border: "1px solid #e1e3e5", boxShadow: "0 1px 2px rgba(0,0,0,0.05)" }}>
                            <div style={{ fontSize: "32px", marginBottom: "12px" }}>👥</div>
                            <h3 style={{ fontSize: "16px", fontWeight: 700, color: "#1a1d20", margin: 0 }}>
                                Customers
                            </h3>
                            <p style={{ fontSize: "13px", color: "#616a75", margin: "8px 0 20px 0", lineHeight: "1.4" }}>
                                Sync Shopify store customers, email addresses, and contact info to Zoho Books Contacts.
                            </p>
                            <a
                                href={`/zoho/customers${shopData?.shop_domain ? `?shop=${shopData.shop_domain}` : ""}`}
                                style={{
                                    display: "block",
                                    textAlign: "center",
                                    padding: "10px",
                                    borderRadius: "6px",
                                    border: "1px solid #c9cccf",
                                    backgroundColor: "#ffffff",
                                    color: "#202223",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    textDecoration: "none",
                                }}
                            >
                                Manage Customers Page →
                            </a>
                        </div>

                        {/* ORDER SYNC CARD */}
                        <div style={{ backgroundColor: "#ffffff", borderRadius: "10px", padding: "24px", border: "1px solid #e1e3e5", boxShadow: "0 1px 2px rgba(0,0,0,0.05)" }}>
                            <div style={{ fontSize: "32px", marginBottom: "12px" }}>📄</div>
                            <h3 style={{ fontSize: "16px", fontWeight: 700, color: "#1a1d20", margin: 0 }}>
                                Orders & Invoices
                            </h3>
                            <p style={{ fontSize: "13px", color: "#616a75", margin: "8px 0 20px 0", lineHeight: "1.4" }}>
                                Sync Shopify orders into Zoho Sales Orders and generate Zoho Books invoices automatically.
                            </p>
                            <a
                                href={`/zoho/orders${shopData?.shop_domain ? `?shop=${shopData.shop_domain}` : ""}`}
                                style={{
                                    display: "block",
                                    textAlign: "center",
                                    padding: "10px",
                                    borderRadius: "6px",
                                    border: "1px solid #c9cccf",
                                    backgroundColor: "#ffffff",
                                    color: "#202223",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    textDecoration: "none",
                                }}
                            >
                                Manage Orders & Invoices →
                            </a>
                        </div>
                    </div>
                ) : (
                    <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "20px" }}>
                        {/* INVENTORY SYNC CARD */}
                        <div style={{ backgroundColor: "#ffffff", borderRadius: "10px", padding: "24px", border: "1px solid #e1e3e5", boxShadow: "0 1px 2px rgba(0,0,0,0.05)" }}>
                            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start" }}>
                                <div style={{ fontSize: "32px", marginBottom: "12px" }}>📊</div>
                                <span
                                    style={{
                                        fontSize: "12px",
                                        fontWeight: 600,
                                        padding: "4px 8px",
                                        borderRadius: "12px",
                                        backgroundColor:
                                            capabilityState === "zoho_inventory" || capabilityState === "zoho_erp"
                                                ? "#eafbdf"
                                                : capabilityState === "books_native"
                                                ? "#fff8e6"
                                                : "#fbeae8",
                                        color:
                                            capabilityState === "zoho_inventory" || capabilityState === "zoho_erp"
                                                ? "#108043"
                                                : capabilityState === "books_native"
                                                ? "#b7791f"
                                                : "#d72c0d",
                                        border:
                                            capabilityState === "zoho_inventory" || capabilityState === "zoho_erp"
                                                ? "1px solid #b7eb8f"
                                                : capabilityState === "books_native"
                                                ? "1px solid #fbd38d"
                                                : "1px solid #f3baba",
                                    }}
                                >
                                    {capabilityState === "zoho_inventory" && "Available (Zoho Inventory API)"}
                                    {capabilityState === "zoho_erp" && "Available (Zoho ERP API)"}
                                    {capabilityState === "books_native" && "Requires Zoho Inventory/ERP"}
                                    {capabilityState === "unavailable" && "Unavailable"}
                                </span>
                            </div>

                            <h3 style={{ fontSize: "16px", fontWeight: 700, color: "#1a1d20", margin: 0 }}>
                                Inventory Stock Levels
                            </h3>
                            <p style={{ fontSize: "13px", color: "#616a75", margin: "8px 0 16px 0", lineHeight: "1.4" }}>
                                Synchronize stock quantities between Zoho Books inventory adjustments and Shopify inventory levels.
                            </p>

                            {capabilityState === "books_native" && (
                                <div style={{ padding: "10px 12px", borderRadius: "6px", backgroundColor: "#fff8e6", border: "1px solid #fbd38d", fontSize: "12px", color: "#744210", marginBottom: "16px", lineHeight: "1.4" }}>
                                    <strong>Notice:</strong> Automatic inventory adjustment requires Zoho Inventory/ERP API access. Zoho Books native inventory adjustments are available in the UI but are not exposed through the current documented Books REST API.
                                </div>
                            )}

                            {capabilityState === "unavailable" && (
                                <div style={{ padding: "10px 12px", borderRadius: "6px", backgroundColor: "#fbeae8", border: "1px solid #f3baba", fontSize: "12px", color: "#9b2c2c", marginBottom: "16px", lineHeight: "1.4" }}>
                                    <strong>Unavailable:</strong> Zoho inventory endpoints are unavailable or permission is disabled for this organization.
                                </div>
                            )}

                            <button
                                type="button"
                                onClick={handleInventorySync}
                                disabled={syncingType === "inventory"}
                                style={{
                                    width: "100%",
                                    padding: "10px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: capabilityState === "books_native" ? "#616a75" : "#005bd3",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: syncingType === "inventory" ? "wait" : "pointer",
                                }}
                            >
                                {syncingType === "inventory" ? "Syncing Stock..." : "Sync All Zoho Inventory"}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </ZohoLayout>
    );
}
