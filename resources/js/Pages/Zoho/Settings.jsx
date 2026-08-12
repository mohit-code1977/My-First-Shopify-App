import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";

export default function Settings({ shop, zohoConnection, host }) {
    const [disconnecting, setDisconnecting] = useState(false);

    const connected = Boolean(zohoConnection);

    const disconnect = () => {
        if (disconnecting) {
            return;
        }

        const confirmed = window.confirm(
            "Are you sure you want to disconnect Zoho Books?",
        );

        if (!confirmed) {
            return;
        }

        setDisconnecting(true);

        router.post(
            "/zoho/settings/disconnect",
            {},
            {
                preserveScroll: true,

                onFinish: () => {
                    setDisconnecting(false);
                },
            },
        );
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
                                {shop?.shop_domain || "Unknown store"}
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
                                                {zohoConnection.organization_id ||
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
                                                {zohoConnection.expires_at ||
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
                                            onClick={disconnect}
                                        >
                                            {disconnecting
                                                ? "Disconnecting..."
                                                : "Disconnect"}
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
                                            onClick={() => {
                                                const params =
                                                    new URLSearchParams({
                                                        shop:
                                                            shop?.shop_domain ||
                                                            "",
                                                        host: host || "",
                                                    });

                                                const connectUrl = `${window.location.origin}/zoho/connect?${params.toString()}`;

                                                window.open(connectUrl, "_top");
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
        </>
    );
}
