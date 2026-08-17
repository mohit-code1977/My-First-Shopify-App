import React, { useEffect, useState } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

const ZOHO_PAYMENT_MODES = [
    { value: "creditcard", label: "Credit Card" },
    { value: "paypal", label: "PayPal" },
    { value: "cash", label: "Cash" },
    { value: "banktransfer", label: "Bank Transfer" },
    { value: "bankremittance", label: "Bank Remittance" },
    { value: "check", label: "Check" },
    { value: "autotransaction", label: "Auto Transaction" },
    { value: "others", label: "Others" },
];

export default function Settings({ shop, zohoConnection, host }) {
    const [shopData, setShopData] = useState(shop || {});
    const [zohoConn, setZohoConn] = useState(zohoConnection);
    const [loading, setLoading] = useState(true);
    const [disconnecting, setDisconnecting] = useState(false);
    const [showDisconnectModal, setShowDisconnectModal] = useState(false);

    // Payment settings state
    const [gatewaySettings, setGatewaySettings] = useState([]);
    const [zohoAccounts, setZohoAccounts] = useState([]);
    const [savingSettings, setSavingSettings] = useState(false);
    const [notification, setNotification] = useState(null);

    const connected = Boolean(zohoConn);

    const loadData = async () => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch("/api/zoho/settings", { headers });
            const data = await response.json();
            if (response.ok && data.success) {
                if (data.shop) setShopData(data.shop);
                setZohoConn(data.zohoConnection);
                if (data.paymentGatewaySettings) {
                    setGatewaySettings(data.paymentGatewaySettings);
                }
                if (data.zohoAccounts) {
                    setZohoAccounts(data.zohoAccounts);
                }
            }
        } catch (error) {
            console.error("Failed to load settings data:", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    const handleGatewayChange = (index, field, value) => {
        const updated = [...gatewaySettings];
        updated[index] = { ...updated[index], [field]: value };
        setGatewaySettings(updated);
    };

    const savePaymentSettings = async () => {
        setSavingSettings(true);
        setNotification(null);
        try {
            const token = await window.shopify?.idToken();
            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const response = await fetch("/zoho/settings/payment-gateways", {
                method: "POST",
                headers: {
                    Authorization: token ? `Bearer ${token}` : "",
                    "X-CSRF-TOKEN": csrfToken,
                    Accept: "application/json",
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    gateways: gatewaySettings,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: data.message || "Payment gateway settings saved successfully.",
                });
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Failed to save payment gateway settings.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error saving payment settings.",
            });
        } finally {
            setSavingSettings(false);
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
            zohoConnected={connected}
            host={host}
            activePage="settings"
        >
            <main className="zoho-content" style={{ padding: 0 }}>
                {notification && (
                    <div
                        style={{
                            padding: "12px 16px",
                            borderRadius: "8px",
                            fontSize: "14px",
                            fontWeight: 500,
                            marginBottom: "20px",
                            backgroundColor:
                                notification.type === "success"
                                    ? "#eafbdf"
                                    : "#fbeae8",
                            color:
                                notification.type === "success"
                                    ? "#108043"
                                    : "#d72c0d",
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
                            {connected ? (
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

                    {/* PAYMENT CONFIGURATION CARD */}

                    <section className="settings-card">
                        <div className="settings-card-header">
                            <div>
                                <h2>Payment Configuration</h2>

                                <p>
                                    Map Shopify payment gateways to Zoho payment modes and deposit accounts.
                                </p>
                            </div>

                            <div className="settings-card-icon">💳</div>
                        </div>

                        <div className="settings-body">
                            {gatewaySettings.length === 0 ? (
                                <div style={{ color: "#616a75", fontSize: "13px" }}>
                                    Loading gateway settings...
                                </div>
                            ) : (
                                <div style={{ display: "flex", flexDirection: "column", gap: "16px" }}>
                                    {gatewaySettings.map((gw, idx) => (
                                        <div
                                            key={gw.shopify_gateway}
                                            style={{
                                                display: "grid",
                                                gridTemplateColumns: "1.2fr 1fr 1fr",
                                                gap: "12px",
                                                alignItems: "center",
                                                padding: "12px",
                                                backgroundColor: "#f8f9fa",
                                                borderRadius: "8px",
                                                border: "1px solid #e1e3e5",
                                            }}
                                        >
                                            <div>
                                                <div style={{ fontWeight: 600, fontSize: "13px", color: "#1a1d20" }}>
                                                    {gw.gateway_label || gw.shopify_gateway}
                                                </div>
                                                <div style={{ fontSize: "11px", color: "#616a75", fontFamily: "monospace" }}>
                                                    {gw.shopify_gateway}
                                                </div>
                                            </div>

                                            <div>
                                                <label style={{ display: "block", fontSize: "11px", fontWeight: 600, color: "#616a75", marginBottom: "4px" }}>
                                                    Zoho Payment Mode
                                                </label>
                                                <select
                                                    value={gw.payment_mode || "creditcard"}
                                                    onChange={(e) => handleGatewayChange(idx, "payment_mode", e.target.value)}
                                                    style={{
                                                        width: "100%",
                                                        padding: "6px 10px",
                                                        borderRadius: "6px",
                                                        border: "1px solid #c9cccf",
                                                        fontSize: "12px",
                                                        backgroundColor: "#ffffff",
                                                    }}
                                                >
                                                    {ZOHO_PAYMENT_MODES.map((mode) => (
                                                        <option key={mode.value} value={mode.value}>
                                                            {mode.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>

                                            <div>
                                                <label style={{ display: "block", fontSize: "11px", fontWeight: 600, color: "#616a75", marginBottom: "4px" }}>
                                                    Zoho Account
                                                </label>
                                                <select
                                                    value={gw.account_id || ""}
                                                    onChange={(e) => handleGatewayChange(idx, "account_id", e.target.value)}
                                                    style={{
                                                        width: "100%",
                                                        padding: "6px 10px",
                                                        borderRadius: "6px",
                                                        border: "1px solid #c9cccf",
                                                        fontSize: "12px",
                                                        backgroundColor: "#ffffff",
                                                    }}
                                                >
                                                    <option value="">Default (Unassigned)</option>
                                                    {zohoAccounts.map((acc) => (
                                                        <option key={acc.account_id} value={acc.account_id}>
                                                            {acc.account_name} ({acc.account_type})
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                    ))}

                                    <div style={{ display: "flex", justifyContent: "flex-end", marginTop: "8px" }}>
                                        <button
                                            type="button"
                                            className="primary-btn"
                                            onClick={savePaymentSettings}
                                            disabled={savingSettings}
                                            style={{
                                                padding: "8px 16px",
                                                borderRadius: "6px",
                                                border: "1px solid #005bd3",
                                                backgroundColor: "#005bd3",
                                                color: "#ffffff",
                                                fontSize: "13px",
                                                fontWeight: 600,
                                                cursor: savingSettings ? "wait" : "pointer",
                                            }}
                                        >
                                            {savingSettings ? "Saving Settings..." : "Save Payment Mappings"}
                                        </button>
                                    </div>
                                </div>
                            )}
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
                            {connected ? (
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
