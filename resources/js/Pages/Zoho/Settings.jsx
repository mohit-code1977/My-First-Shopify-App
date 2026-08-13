import React, { useEffect, useState } from "react";
import { Head } from "@inertiajs/react";

export default function Settings({ shop, zohoConnection, host }) {
    const [shopData, setShopData] = useState(shop || {});
    const [zohoConn, setZohoConn] = useState(zohoConnection);
    const [loading, setLoading] = useState(true);
    const [disconnecting, setDisconnecting] = useState(false);
    const [showDisconnectModal, setShowDisconnectModal] = useState(false);

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
        <>
            <Head title="Settings" />

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
                                {shopData?.shop_domain || "Unknown store"}
                            </div>
                        </div>
                    </div>

                    <div
                        className={
                            connected
                                ? "connection-badge"
                                : "connection-badge disconnected"
                        }
                    >
                        <span className="connection-dot" />

                        {connected ? "Connected" : "Not Connected"}
                    </div>
                </header>

                {/* CONTENT */}

                <main className="zoho-content">
                    <section className="page-intro">
                        <div>
                            <span className="eyebrow">CONFIGURATION</span>

                            <h1>Settings</h1>

                            <p>
                                Manage your Zoho Books connection and
                                integration settings.
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
                                        Connection details for your Zoho Books
                                        organization.
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
                                                    Current Zoho Books
                                                    connection status.
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
                                                    Your Zoho Books
                                                    organization.
                                                </div>
                                            </div>

                                            <div className="setting-value">
                                                {zohoConn.organization_id ||
                                                    "—"}
                                            </div>
                                        </div>

                                        <div className="setting-row">
                                            <div>
                                                <div className="setting-label">
                                                    Token Expires
                                                </div>

                                                <div className="setting-description">
                                                    Zoho access token expiration
                                                    time.
                                                </div>
                                            </div>

                                            <div className="setting-value">
                                                {zohoConn.expires_at ||
                                                    "—"}
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <div className="empty-state compact">
                                        <div className="empty-state-icon">
                                            !
                                        </div>

                                        <strong>
                                            Zoho Books is not connected
                                        </strong>

                                        <p>
                                            Connect your Zoho Books account to
                                            start synchronizing products.
                                        </p>
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
                                        Manage the connection between Shopify
                                        and Zoho Books.
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
                                                Remove the current Zoho Books
                                                connection from this Shopify
                                                store.
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            className="danger-btn"
                                            disabled={disconnecting}
                                            onClick={() =>
                                                setShowDisconnectModal(true)
                                            }
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
                                                Authorize Zoho Books and connect
                                                it to this Shopify store.
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            className="primary-btn"
                                            onClick={async () => {
                                                try {
                                                    const token =
                                                        await window.shopify?.idToken();
                                                    const response =
                                                        await fetch(
                                                            "/api/zoho/connect",
                                                            {
                                                                method: "POST",
                                                                headers: {
                                                                    "Content-Type":
                                                                        "application/json",
                                                                    Accept: "application/json",
                                                                    Authorization:
                                                                        token
                                                                            ? `Bearer ${token}`
                                                                            : "",
                                                                },
                                                                body: JSON.stringify(
                                                                    {
                                                                        host:
                                                                            host ||
                                                                            "",
                                                                    },
                                                                ),
                                                            },
                                                        );
                                                    const data =
                                                        await response.json();
                                                    if (
                                                        response.ok &&
                                                        data.redirect_url
                                                    ) {
                                                        window.open(
                                                            data.redirect_url,
                                                            "_top",
                                                        );
                                                    } else {
                                                        alert(
                                                            data.error ||
                                                                "Failed to initiate Zoho connection.",
                                                        );
                                                    }
                                                } catch (err) {
                                                    console.error(
                                                        "Zoho connect error:",
                                                        err,
                                                    );
                                                    alert(
                                                        "Failed to initiate Zoho connection.",
                                                    );
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
                                        Current synchronization behavior of the
                                        integration.
                                    </p>
                                </div>

                                <div className="settings-card-icon">↻</div>
                            </div>

                            <div className="settings-body">
                                <div className="setting-row">
                                    <div>
                                        <div className="setting-label">
                                            Product Synchronization
                                        </div>

                                        <div className="setting-description">
                                            Products can be synchronized
                                            manually from the Products page.
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
                                            Duplicate Protection
                                        </div>

                                        <div className="setting-description">
                                            Existing Zoho items are updated
                                            instead of creating duplicates.
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
                                            Every synchronization attempt is
                                            recorded in the activity log.
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
            </div>

            {showDisconnectModal && (
                <div className="confirm-modal-overlay">
                    <div className="confirm-modal">
                        <div className="confirm-modal-icon">!</div>

                        <h3>Disconnect Zoho Books?</h3>

                        <p>
                            This will remove the Zoho Books connection from this
                            Shopify store. Your existing products and sync
                            history will be preserved.
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
                                {disconnecting
                                    ? "Disconnecting..."
                                    : "Disconnect"}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
