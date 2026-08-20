import React, { useEffect, useState } from "react";
import { Banner, Badge, Button, InlineStack, Text } from "@shopify/polaris";
import ZohoLayout from "@/Layouts/ZohoLayout";

export default function Settings({ shop, zohoConnection, zohoConnected, host }) {
    const [shopData, setShopData] = useState(shop || {});
    const [zohoConn, setZohoConn] = useState(zohoConnection || null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(null);
    const [disconnecting, setDisconnecting] = useState(false);
    const [showDisconnectModal, setShowDisconnectModal] = useState(false);
    const [notification, setNotification] = useState(null);

    // Explicit connection status: 'loading' | 'connected' | 'disconnected' | 'error'
    const [connectionStatus, setConnectionStatus] = useState(() => {
        if (zohoConnected === true || Boolean(zohoConnection)) {
            return "connected";
        }
        if (zohoConnected === false && !zohoConnection) {
            return "disconnected";
        }
        return "loading";
    });

    // Tax settings state
    const [taxSettings, setTaxSettings] = useState({
        tax_mode: "exclusive",
        default_tax_id: "",
        shipping_tax_mode: "use_order_tax",
        discount_tax_mode: "before_tax",
        tax_mappings: [],
    });
    const [zohoTaxes, setZohoTaxes] = useState([]);
    const [savingTaxSettings, setSavingTaxSettings] = useState(false);

    const loadData = async () => {
        setLoading(true);
        setLoadError(null);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch("/api/zoho/settings", { headers });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: Failed to load settings.`);
            }
            const data = await response.json();
            if (data.success) {
                if (data.shop) setShopData(data.shop);
                setZohoConn(data.zohoConnection || null);
                if (data.taxSettings) {
                    setTaxSettings(data.taxSettings);
                }
                if (data.zohoTaxes) {
                    setZohoTaxes(data.zohoTaxes);
                }

                const isConn = Boolean(data.zohoConnection);
                setConnectionStatus(isConn ? "connected" : "disconnected");
            } else {
                throw new Error(data.message || "Failed to load settings data.");
            }
        } catch (error) {
            console.error("Failed to load settings data:", error);
            setLoadError(error.message || "Unable to load Zoho connection status. Please refresh.");
            // Do NOT mark as disconnected on API error!
            setConnectionStatus("error");
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    const handleTaxSettingChange = (field, value) => {
        setTaxSettings((prev) => ({ ...prev, [field]: value }));
    };

    const handleMappingChange = (index, field, value) => {
        const updated = [...(taxSettings.tax_mappings || [])];
        updated[index] = { ...updated[index], [field]: value };
        setTaxSettings((prev) => ({ ...prev, tax_mappings: updated }));
    };

    const addTaxMappingRow = () => {
        const current = taxSettings.tax_mappings || [];
        if (current.length >= 50) {
            setNotification({
                type: "error",
                message: "Maximum limit of 50 tax mappings reached per shop.",
            });
            return;
        }
        const updated = [...current];
        updated.push({ shopify_tax_name: "GST", shopify_rate: 5, zoho_tax_id: "" });
        setTaxSettings((prev) => ({ ...prev, tax_mappings: updated }));
    };

    const removeTaxMappingRow = (index) => {
        const updated = (taxSettings.tax_mappings || []).filter((_, i) => i !== index);
        setTaxSettings((prev) => ({ ...prev, tax_mappings: updated }));
    };



    const saveTaxSettingsApi = async () => {
        const mappings = taxSettings.tax_mappings || [];

        if (mappings.length > 50) {
            setNotification({
                type: "error",
                message: "Maximum limit of 50 tax mappings allowed per shop.",
            });
            return;
        }

        const seen = new Set();
        for (let i = 0; i < mappings.length; i++) {
            const map = mappings[i];
            const name = (map.shopify_tax_name || "").trim().toLowerCase();
            const rateStr = map.shopify_rate;

            if (rateStr !== "" && rateStr !== null && rateStr !== undefined) {
                const rateNum = parseFloat(rateStr);
                if (isNaN(rateNum) || rateNum < 0 || rateNum > 100) {
                    setNotification({
                        type: "error",
                        message: `Tax rate for "${map.shopify_tax_name || "Row " + (i + 1)}" must be a number between 0% and 100%.`,
                    });
                    return;
                }
            }

            if (name !== "" && rateStr !== "" && rateStr !== null && rateStr !== undefined) {
                const rateNum = Math.round(parseFloat(rateStr) * 100) / 100;
                const key = `${name}:${rateNum}`;
                if (seen.has(key)) {
                    const displayName = map.shopify_tax_name || "Tax";
                    setNotification({
                        type: "error",
                        message: `Duplicate tax mapping detected for "${displayName}" at rate ${rateNum}%. Each Shopify tax name and rate combination must be unique.`,
                    });
                    return;
                }
                seen.add(key);
            }
        }

        setSavingTaxSettings(true);
        setNotification(null);
        try {
            const token = await window.shopify?.idToken();
            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const response = await fetch("/zoho/settings/tax", {
                method: "POST",
                headers: {
                    Authorization: token ? `Bearer ${token}` : "",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(taxSettings),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: data.message || "Tax configuration saved successfully.",
                });
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Failed to save tax configuration.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error saving tax settings.",
            });
        } finally {
            setSavingTaxSettings(false);
        }
    };

    const disconnect = async () => {
        if (disconnecting) {
            return;
        }

        setDisconnecting(true);

        try {
            const token = await window.shopify?.idToken();
            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const response = await fetch("/zoho/settings/disconnect", {
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
                throw new Error(
                    data.message || "Failed to disconnect Zoho Books.",
                );
            }

            await loadData();
        } catch (error) {
            console.error("Disconnect failed:", error);

            window.alert(error?.message || "Failed to disconnect Zoho Books.");
        } finally {
            setDisconnecting(false);
        }
    };

    return (
        <ZohoLayout
            title="Settings | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectionStatus === "connected"}
            connectionStatus={connectionStatus}
            host={host}
            activePage="settings"
        >
            <main className="zoho-content" style={{ padding: 0 }}>
                {notification && (
                    <div style={{ marginBottom: "20px" }}>
                        <Banner
                            tone={notification.type === "success" ? "success" : notification.type === "warning" ? "warning" : "critical"}
                            onDismiss={() => setNotification(null)}
                        >
                            <p>{notification.message}</p>
                        </Banner>
                    </div>
                )}

                <section className="page-intro">
                    <div>
                        <span className="eyebrow">CONFIGURATION</span>

                        <h1>Settings</h1>

                        <p>
                            Manage your Zoho Books connection and integration settings.
                        </p>
                    </div>
                </section>

                <div className="settings-layout">
                    {/* CONNECTION */}

                    <section className="settings-card">
                        <div className="settings-card-header">
                            <div>
                                <h2>Zoho Books Connection</h2>

                                <p>
                                    Connection details for your Zoho Books organization.
                                </p>
                            </div>

                            <div className="settings-card-icon">Z</div>
                        </div>

                        <div className="settings-body">
                            {connectionStatus === "loading" ? (
                                <div style={{ padding: "16px", color: "#616a75", fontSize: "13px" }}>
                                    <div style={{ display: "flex", alignItems: "center", gap: "8px", marginBottom: "6px" }}>
                                        <span style={{ fontSize: "14px" }}>⏳</span>
                                        <strong>Checking Zoho Books connection...</strong>
                                    </div>
                                    <p style={{ margin: 0, fontSize: "12px", color: "#8c9196" }}>
                                        Fetching connection parameters and organization status.
                                    </p>
                                </div>
                            ) : connectionStatus === "error" ? (
                                <div style={{ padding: "14px", backgroundColor: "#fff5f5", border: "1px solid #fed7d7", borderRadius: "6px", color: "#c53030", fontSize: "13px" }}>
                                    <strong>Unable to load Zoho connection status.</strong>
                                    <p style={{ margin: "4px 0 8px 0", fontSize: "12px" }}>
                                        {loadError || "Please check your network connection and try again."}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={loadData}
                                        style={{
                                            padding: "4px 12px",
                                            borderRadius: "4px",
                                            border: "1px solid #c9cccf",
                                            backgroundColor: "#ffffff",
                                            fontSize: "12px",
                                            fontWeight: 600,
                                            cursor: "pointer",
                                        }}
                                    >
                                        Retry
                                    </button>
                                </div>
                            ) : connectionStatus === "connected" && zohoConn ? (
                                <>
                                    <div className="setting-row">
                                        <div>
                                            <div className="setting-label">
                                                Connection Status
                                            </div>

                                            <div className="setting-description">
                                                Current Zoho Books connection status.
                                            </div>
                                        </div>

                                        <span className="status synced">
                                            <span className="status-dot" />
                                            Connected
                                        </span>
                                    </div>

                                    <div className="setting-row">
                                        <div>
                                            <div className="setting-label">
                                                Organization ID
                                            </div>

                                            <div className="setting-description">
                                                Your Zoho Books organization.
                                            </div>
                                        </div>

                                        <div className="setting-value">
                                            {zohoConn.organization_id || "—"}
                                        </div>
                                    </div>

                                    <div className="setting-row">
                                        <div>
                                            <div className="setting-label">
                                                Token Expires
                                            </div>

                                            <div className="setting-description">
                                                Zoho access token expiration time.
                                            </div>
                                        </div>

                                        <div className="setting-value">
                                            {zohoConn.expires_at || "—"}
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <div className="empty-state compact">
                                    <div className="empty-state-icon">!</div>

                                    <strong>Zoho Books is not connected</strong>

                                    <p>
                                        Connect your Zoho Books account to start synchronizing products, orders, and payments.
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>



                    {/* TAX CONFIGURATION CARD */}

                    <section className="settings-card">
                        <div className="settings-card-header">
                            <div>
                                <h2>Tax Configuration</h2>

                                <p>
                                    Configure tax modes, default Zoho tax rules, shipping & discount tax handling, and Shopify-to-Zoho tax mappings.
                                </p>
                            </div>

                            <div className="settings-card-icon">🏷️</div>
                        </div>

                        <div className="settings-body">
                            <div style={{ display: "flex", flexDirection: "column", gap: "20px" }}>
                                <div style={{ padding: "12px 14px", backgroundColor: "#fbf6ed", border: "1px solid #e1b878", borderRadius: "6px", fontSize: "12px", color: "#614700" }}>
                                    <strong>💡 Tax Sync & GST Notice:</strong> GST-specific operations require GST activation under <em>Settings → Tax Settings</em> in your Zoho Books portal. Orders with 0% tax sync without tax lines. Tax mappings require exact rate parity (e.g. Shopify 18% must match Zoho tax rate of 18%).
                                </div>
                                {/* Tax Mode & Default Tax */}
                                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "16px" }}>
                                    <div>
                                        <label style={{ display: "block", fontSize: "12px", fontWeight: 600, color: "#1a1d20", marginBottom: "6px" }}>
                                            Tax Mode (Pricing Type)
                                        </label>
                                        <select
                                            value={taxSettings.tax_mode || "exclusive"}
                                            onChange={(e) => handleTaxSettingChange("tax_mode", e.target.value)}
                                            style={{
                                                width: "100%",
                                                padding: "8px 12px",
                                                borderRadius: "6px",
                                                border: "1px solid #c9cccf",
                                                fontSize: "13px",
                                                backgroundColor: "#ffffff",
                                            }}
                                        >
                                            <option value="exclusive">Tax Exclusive (Tax added at checkout)</option>
                                            <option value="inclusive">Tax Inclusive (Prices include tax)</option>
                                        </select>
                                        <span style={{ fontSize: "11px", color: "#616a75", marginTop: "4px", display: "block" }}>
                                            Applies default tax handling if Shopify order tax flags are missing.
                                        </span>
                                    </div>

                                    <div>
                                        <label style={{ display: "block", fontSize: "12px", fontWeight: 600, color: "#1a1d20", marginBottom: "6px" }}>
                                            Default Zoho Tax Rate
                                        </label>
                                        <select
                                            value={taxSettings.default_tax_id || ""}
                                            onChange={(e) => handleTaxSettingChange("default_tax_id", e.target.value)}
                                            style={{
                                                width: "100%",
                                                padding: "8px 12px",
                                                borderRadius: "6px",
                                                border: "1px solid #c9cccf",
                                                fontSize: "13px",
                                                backgroundColor: "#ffffff",
                                            }}
                                        >
                                            <option value="">No Default Tax (0% / Exempt)</option>
                                            {zohoTaxes.map((tax) => (
                                                <option key={tax.tax_id} value={tax.tax_id}>
                                                    {tax.tax_name} ({tax.tax_percentage}%)
                                                </option>
                                            ))}
                                        </select>
                                        <span style={{ fontSize: "11px", color: "#616a75", marginTop: "4px", display: "block" }}>
                                            Fallback tax rate used when no specific mapping matches.
                                        </span>
                                    </div>
                                </div>

                                {/* Shipping Tax Mode & Discount Tax Mode */}
                                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "16px" }}>
                                    <div>
                                        <label style={{ display: "block", fontSize: "12px", fontWeight: 600, color: "#1a1d20", marginBottom: "6px" }}>
                                            Shipping Tax Handling
                                        </label>
                                        <select
                                            value={taxSettings.shipping_tax_mode || "use_order_tax"}
                                            onChange={(e) => handleTaxSettingChange("shipping_tax_mode", e.target.value)}
                                            style={{
                                                width: "100%",
                                                padding: "8px 12px",
                                                borderRadius: "6px",
                                                border: "1px solid #c9cccf",
                                                fontSize: "13px",
                                                backgroundColor: "#ffffff",
                                            }}
                                        >
                                            <option value="use_order_tax">Inherit / Preserved from Order Tax</option>
                                            <option value="separate_shipping_tax">Separate Shipping Tax</option>
                                            <option value="no_tax">Exempt Shipping from Tax</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label style={{ display: "block", fontSize: "12px", fontWeight: 600, color: "#1a1d20", marginBottom: "6px" }}>
                                            Discount Tax Handling
                                        </label>
                                        <select
                                            value={taxSettings.discount_tax_mode || "before_tax"}
                                            onChange={(e) => handleTaxSettingChange("discount_tax_mode", e.target.value)}
                                            style={{
                                                width: "100%",
                                                padding: "8px 12px",
                                                borderRadius: "6px",
                                                border: "1px solid #c9cccf",
                                                fontSize: "13px",
                                                backgroundColor: "#ffffff",
                                            }}
                                        >
                                            <option value="before_tax">Apply Tax Before Discount (Pre-Tax Discount)</option>
                                            <option value="after_tax">Apply Tax After Discount (Post-Tax Discount)</option>
                                        </select>
                                    </div>
                                </div>

                                {/* Limit Warning Banner */}
                                {(taxSettings.tax_mappings || []).length >= 50 && (
                                    <div style={{ marginBottom: "12px" }}>
                                        <Banner tone="warning" title="Tax Mapping Limit Reached">
                                            <p>
                                                You have reached the maximum limit of 50 tax mappings. Please delete an existing tax mapping before adding a new one.
                                            </p>
                                        </Banner>
                                    </div>
                                )}

                                {/* Tax Mapping Table */}
                                <div style={{ marginTop: "10px" }}>
                                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "10px" }}>
                                        <InlineStack gap="200" align="center">
                                            <div style={{ fontWeight: 600, fontSize: "13px", color: "#1a1d20" }}>
                                                Shopify to Zoho Tax Mappings
                                            </div>
                                            <Badge tone={(taxSettings.tax_mappings || []).length >= 50 ? "warning" : "info"}>
                                                {`Tax mappings: ${(taxSettings.tax_mappings || []).length} / 50`}
                                            </Badge>
                                        </InlineStack>

                                        <Button
                                            onClick={addTaxMappingRow}
                                            disabled={(taxSettings.tax_mappings || []).length >= 50}
                                            size="micro"
                                        >
                                            + Add Tax Mapping
                                        </Button>
                                    </div>

                                    {(taxSettings.tax_mappings || []).length === 0 ? (
                                        <div style={{ padding: "12px", backgroundColor: "#f8f9fa", borderRadius: "6px", fontSize: "12px", color: "#616a75" }}>
                                            No tax mappings configured. Add a mapping to match Shopify tax rates (e.g. GST 5%) with your Zoho Tax rates.
                                        </div>
                                    ) : (
                                        <div style={{ display: "flex", flexDirection: "column", gap: "8px" }}>
                                            {(taxSettings.tax_mappings || []).map((map, idx) => (
                                                <div
                                                    key={idx}
                                                    style={{
                                                        display: "grid",
                                                        gridTemplateColumns: "1.5fr 1fr 2fr 40px",
                                                        gap: "10px",
                                                        alignItems: "center",
                                                        padding: "10px",
                                                        backgroundColor: "#f8f9fa",
                                                        borderRadius: "6px",
                                                        border: "1px solid #e1e3e5",
                                                    }}
                                                >
                                                    <div>
                                                        <input
                                                            type="text"
                                                            placeholder="Shopify Tax Name (e.g. GST)"
                                                            value={map.shopify_tax_name || ""}
                                                            onChange={(e) => handleMappingChange(idx, "shopify_tax_name", e.target.value)}
                                                            style={{
                                                                width: "100%",
                                                                padding: "6px 10px",
                                                                borderRadius: "4px",
                                                                border: "1px solid #c9cccf",
                                                                fontSize: "12px",
                                                            }}
                                                        />
                                                    </div>

                                                    <div>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            placeholder="Rate % (e.g. 5)"
                                                            value={map.shopify_rate ?? ""}
                                                            onChange={(e) => handleMappingChange(idx, "shopify_rate", e.target.value)}
                                                            style={{
                                                                width: "100%",
                                                                padding: "6px 10px",
                                                                borderRadius: "4px",
                                                                border: "1px solid #c9cccf",
                                                                fontSize: "12px",
                                                            }}
                                                        />
                                                    </div>

                                                    <div>
                                                        {(() => {
                                                            const selTax = zohoTaxes.find(t => String(t.tax_id) === String(map.zoho_tax_id));
                                                            const isMismatch = selTax && map.shopify_rate !== "" && Math.abs(Number(map.shopify_rate) - Number(selTax.tax_percentage)) > 0.01;
                                                            return (
                                                                <>
                                                                    <select
                                                                        value={map.zoho_tax_id || ""}
                                                                        onChange={(e) => handleMappingChange(idx, "zoho_tax_id", e.target.value)}
                                                                        style={{
                                                                            width: "100%",
                                                                            padding: "6px 10px",
                                                                            borderRadius: "4px",
                                                                            border: isMismatch ? "1px solid #d72c0d" : "1px solid #c9cccf",
                                                                            fontSize: "12px",
                                                                            backgroundColor: "#ffffff",
                                                                        }}
                                                                    >
                                                                        <option value="">Select Zoho Tax Rate...</option>
                                                                        {zohoTaxes.map((tax) => (
                                                                            <option key={tax.tax_id} value={tax.tax_id}>
                                                                                {tax.tax_name} ({tax.tax_percentage}%)
                                                                            </option>
                                                                        ))}
                                                                    </select>
                                                                    {isMismatch && (
                                                                        <span style={{ fontSize: "10px", color: "#d72c0d", fontWeight: 600, display: "block", marginTop: "2px" }}>
                                                                            ⚠️ Rate mismatch: Zoho rate is {selTax.tax_percentage}% vs Shopify {map.shopify_rate}%
                                                                        </span>
                                                                    )}
                                                                </>
                                                            );
                                                        })()}
                                                    </div>

                                                    <button
                                                        type="button"
                                                        onClick={() => removeTaxMappingRow(idx)}
                                                        style={{
                                                            background: "none",
                                                            border: "none",
                                                            color: "#d72c0d",
                                                            fontSize: "16px",
                                                            fontWeight: "bold",
                                                            cursor: "pointer",
                                                        }}
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div style={{ display: "flex", justifyContent: "flex-end", marginTop: "8px" }}>
                                    <button
                                        type="button"
                                        className="primary-btn"
                                        onClick={saveTaxSettingsApi}
                                        disabled={savingTaxSettings}
                                        style={{
                                            padding: "8px 16px",
                                            borderRadius: "6px",
                                            border: "1px solid #005bd3",
                                            backgroundColor: "#005bd3",
                                            color: "#ffffff",
                                            fontSize: "13px",
                                            fontWeight: 600,
                                            cursor: savingTaxSettings ? "wait" : "pointer",
                                        }}
                                    >
                                        {savingTaxSettings ? "Saving Tax Settings..." : "Save Tax Configuration"}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* CONNECTION MANAGEMENT */}

                    <section className="settings-card">
                        <div className="settings-card-header">
                            <div>
                                <h2>Connection Management</h2>

                                <p>
                                    Manage the connection between Shopify and Zoho Books.
                                </p>
                            </div>

                            <div className="settings-card-icon">⚙</div>
                        </div>

                        <div className="settings-body">
                            {connectionStatus === "loading" ? (
                                <div style={{ padding: "12px", color: "#616a75", fontSize: "12px" }}>
                                    Loading connection management options...
                                </div>
                            ) : connectionStatus === "error" ? (
                                <div style={{ padding: "12px", color: "#c53030", fontSize: "12px" }}>
                                    Actions unavailable during connection error.
                                </div>
                            ) : connectionStatus === "connected" ? (
                                <div className="management-block">
                                    <div>
                                        <div className="setting-label">
                                            Disconnect Zoho Books
                                        </div>

                                        <div className="setting-description">
                                            Remove the current Zoho Books connection from this Shopify store.
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        className="danger-btn"
                                        disabled={disconnecting}
                                        onClick={() => setShowDisconnectModal(true)}
                                    >
                                        Disconnect
                                    </button>
                                </div>
                            ) : (
                                <div className="management-block">
                                    <div>
                                        <div className="setting-label">
                                            Connect Zoho Books
                                        </div>

                                        <div className="setting-description">
                                            Authorize Zoho Books and connect it to this Shopify store.
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        className="primary-btn"
                                        onClick={async () => {
                                            try {
                                                const token = await window.shopify?.idToken();
                                                const response = await fetch("/api/zoho/connect", {
                                                    method: "POST",
                                                    headers: {
                                                        "Content-Type": "application/json",
                                                        Accept: "application/json",
                                                        Authorization: token ? `Bearer ${token}` : "",
                                                    },
                                                    body: JSON.stringify({ host: host || "" }),
                                                });
                                                const data = await response.json();
                                                if (response.ok && data.redirect_url) {
                                                    const width = 600;
                                                    const height = 700;
                                                    const left = Math.max(0, Math.floor((window.screen.width - width) / 2));
                                                    const top = Math.max(0, Math.floor((window.screen.height - height) / 2));

                                                    const popup = window.open(
                                                        data.redirect_url,
                                                        "ZohoOAuthPopup",
                                                        `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,status=yes,resizable=yes`
                                                    );

                                                    if (!popup || popup.closed || typeof popup.closed === "undefined") {
                                                        window.open(data.redirect_url, "_top");
                                                    }
                                                } else {
                                                    alert(data.error || "Failed to initiate Zoho connection.");
                                                }
                                            } catch (err) {
                                                console.error("Zoho connect error:", err);
                                                alert("Failed to initiate Zoho connection.");
                                            }
                                        }}
                                    >
                                        Connect Zoho Books
                                    </button>
                                </div>
                            )}
                        </div>
                    </section>

                    {/* SYNCHRONIZATION */}

                    <section className="settings-card">
                        <div className="settings-card-header">
                            <div>
                                <h2>Synchronization</h2>

                                <p>
                                    Current synchronization behavior of the integration.
                                </p>
                            </div>

                            <div className="settings-card-icon">↻</div>
                        </div>

                        <div className="settings-body">
                            <div className="setting-row">
                                <div>
                                    <div className="setting-label">
                                        Product & Order Synchronization
                                    </div>

                                    <div className="setting-description">
                                        Products, orders, invoices, and payments synchronize automatically via webhooks.
                                    </div>
                                </div>

                                <span className="status synced">
                                    <span className="status-dot" />
                                    Active
                                </span>
                            </div>

                            <div className="setting-row">
                                <div>
                                    <div className="setting-label">
                                        Payment Synchronization
                                    </div>

                                    <div className="setting-description">
                                        Customer payments are linked to Zoho Invoices idempotently.
                                    </div>
                                </div>

                                <span className="status synced">
                                    <span className="status-dot" />
                                    Enabled
                                </span>
                            </div>

                            <div className="setting-row">
                                <div>
                                    <div className="setting-label">
                                        Duplicate Protection
                                    </div>

                                    <div className="setting-description">
                                        Existing Zoho items and payments are updated/matched instead of creating duplicates.
                                    </div>
                                </div>

                                <span className="status synced">
                                    <span className="status-dot" />
                                    Enabled
                                </span>
                            </div>

                            <div className="setting-row">
                                <div>
                                    <div className="setting-label">
                                        Sync History
                                    </div>

                                    <div className="setting-description">
                                        Every synchronization attempt is recorded in the activity log.
                                    </div>
                                </div>

                                <span className="status synced">
                                    <span className="status-dot" />
                                    Enabled
                                </span>
                            </div>
                        </div>
                    </section>
                </div>
            </main>

            {showDisconnectModal && (
                <div className="confirm-modal-overlay">
                    <div className="confirm-modal">
                        <div className="confirm-modal-icon">!</div>

                        <h3>Disconnect Zoho Books?</h3>

                        <p>
                            This will remove the Zoho Books connection from this Shopify store. Your existing products and sync history will be preserved.
                        </p>

                        <div className="confirm-modal-actions">
                            <button
                                type="button"
                                className="modal-cancel-btn"
                                onClick={() => setShowDisconnectModal(false)}
                                disabled={disconnecting}
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                className="modal-danger-btn"
                                onClick={() => {
                                    setShowDisconnectModal(false);
                                    disconnect();
                                }}
                                disabled={disconnecting}
                            >
                                {disconnecting ? "Disconnecting..." : "Disconnect"}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ZohoLayout>
    );
}
