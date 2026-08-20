import React, { useState } from "react";
import { Head } from "@inertiajs/react";
import { AppProvider } from "@shopify/polaris";
import enTranslations from "@shopify/polaris/locales/en.json";
import "@shopify/polaris/build/esm/styles.css";

export default function ZohoLayout({
    title = "Zoho Books Integration",
    shop = {},
    shopDomain: shopDomainProp = "",
    zohoConnected = null,
    connectionStatus = null,
    host = "",
    activePage = "products",
    children,
}) {
    const [connecting, setConnecting] = useState(false);

    // Connection state determination: 'loading' | 'connected' | 'disconnected' | 'error'
    const resolveStatus = () => {
        if (connectionStatus) return connectionStatus;
        if (zohoConnected === true) return "connected";
        if (zohoConnected === false) return "disconnected";
        return "loading";
    };

    const effectiveStatus = resolveStatus();

    // Resolve shop domain consistently across props & URL context
    const shopDomain =
        shop?.shop_domain ||
        shop?.domain ||
        shopDomainProp ||
        (typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("shop") : "") ||
        "app-dev-kpaqieri.myshopify.com";

    const getQueryString = () => {
        const params = new URLSearchParams();
        if (shopDomain) params.set("shop", shopDomain);
        if (host) params.set("host", host);
        const str = params.toString();
        return str ? `?${str}` : "";
    };

    const queryString = getQueryString();

    React.useEffect(() => {
        if (window.opener && window.name === "ZohoOAuthPopup") {
            try {
                window.opener.postMessage({ type: "ZOHO_CONNECTED_SUCCESS" }, "*");
            } catch (e) {}
            window.close();
            return;
        }

        const handleMessage = (event) => {
            if (event.data?.type === "ZOHO_CONNECTED_SUCCESS") {
                window.location.reload();
            }
        };
        window.addEventListener("message", handleMessage);
        return () => window.removeEventListener("message", handleMessage);
    }, []);

    const handleConnectZoho = async () => {
        if (connecting) return;
        setConnecting(true);

        try {
            const token = await window.shopify?.idToken();
            const csrfToken =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "";

            const response = await fetch("/api/zoho/connect", {
                method: "POST",
                headers: {
                    Authorization: token ? `Bearer ${token}` : "",
                    "X-CSRF-TOKEN": csrfToken,
                    "Content-Type": "application/json",
                    Accept: "application/json",
                },
                body: JSON.stringify({ host, shop: shopDomain, reconsent: true }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.ok && data.success && data.redirect_url) {
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
                    window.location.href = data.redirect_url;
                }
            } else {
                alert(data.error || data.message || "Zoho reauthorization could not be started. Please try again.");
            }
        } catch (error) {
            console.error("Zoho connection initiation error:", error);
            alert("Zoho reauthorization could not be started. Please try again.");
        } finally {
            setConnecting(false);
        }
    };

    const renderHeaderBadge = () => {
        if (effectiveStatus === "loading") {
            return (
                <div
                    style={{
                        display: "flex",
                        alignItems: "center",
                        gap: "8px",
                        padding: "6px 14px",
                        borderRadius: "20px",
                        fontSize: "13px",
                        fontWeight: 600,
                        backgroundColor: "#f1f2f4",
                        color: "#616a75",
                        border: "1px solid #d3d5d8",
                    }}
                >
                    <span
                        style={{
                            width: "8px",
                            height: "8px",
                            borderRadius: "50%",
                            backgroundColor: "#c9cccf",
                        }}
                    />
                    Checking Status...
                </div>
            );
        }

        if (effectiveStatus === "error") {
            return (
                <div
                    style={{
                        display: "flex",
                        alignItems: "center",
                        gap: "8px",
                        padding: "6px 14px",
                        borderRadius: "20px",
                        fontSize: "13px",
                        fontWeight: 600,
                        backgroundColor: "#fff5f5",
                        color: "#c53030",
                        border: "1px solid #fed7d7",
                    }}
                >
                    <span
                        style={{
                            width: "8px",
                            height: "8px",
                            borderRadius: "50%",
                            backgroundColor: "#d72c0d",
                        }}
                    />
                    Connection Error
                </div>
            );
        }

        const isConn = effectiveStatus === "connected";
        return (
            <div
                style={{
                    display: "flex",
                    alignItems: "center",
                    gap: "8px",
                    padding: "6px 14px",
                    borderRadius: "20px",
                    fontSize: "13px",
                    fontWeight: 600,
                    backgroundColor: isConn ? "#eafbdf" : "#f1f2f4",
                    color: isConn ? "#108043" : "#616a75",
                    border: isConn ? "1px solid #b7eb8f" : "1px solid #d3d5d8",
                }}
            >
                <span
                    style={{
                        width: "8px",
                        height: "8px",
                        borderRadius: "50%",
                        backgroundColor: isConn ? "#108043" : "#8c9196",
                    }}
                />
                {isConn ? "Connected" : "Not Connected"}
            </div>
        );
    };

    return (
        <AppProvider i18n={enTranslations}>
            <Head title={title} />

            {/* App Bridge Left Navigation Registration */}
            <ui-nav-menu>
                <a href={`/zoho/dashboard${queryString}`}>Dashboard</a>
                <a href={`/zoho/products${queryString}`}>Products</a>
                <a href={`/zoho/orders${queryString}`}>Orders &amp; Invoices</a>
                <a href={`/zoho/refunds${queryString}`}>Refunds &amp; Credit Notes</a>
                <a href={`/zoho/customers${queryString}`}>Customers</a>
                <a href={`/zoho/sync/history${queryString}`}>Sync History</a>
                <a href={`/zoho/settings${queryString}`}>Settings</a>
            </ui-nav-menu>

            <div className="zoho-app-shell" style={{ minHeight: "100vh", backgroundColor: "#f6f6f7", display: "flex", flexDirection: "column", fontFamily: "-apple-system, BlinkMacSystemFont, 'San Francisco', 'Segoe UI', Roboto, Helvetica, sans-serif" }}>
                {/* APP HEADER */}
                <header
                    style={{
                        height: "60px",
                        backgroundColor: "#ffffff",
                        borderBottom: "1px solid #e1e3e5",
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "space-between",
                        padding: "0 24px",
                        boxSizing: "border-box",
                        position: "sticky",
                        top: 0,
                        zIndex: 100,
                    }}
                >
                    <div style={{ display: "flex", alignItems: "center", gap: "12px" }}>
                        <div
                            style={{
                                width: "34px",
                                height: "34px",
                                borderRadius: "8px",
                                background: "linear-gradient(135deg, #1070e0 0%, #084e96 100%)",
                                color: "#ffffff",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontWeight: 800,
                                fontSize: "16px",
                                boxShadow: "0 2px 4px rgba(16, 112, 224, 0.2)",
                            }}
                        >
                            Z
                        </div>
                        <div>
                            <div style={{ fontWeight: 700, fontSize: "15px", color: "#1a1d20", lineHeight: "1.2" }}>
                                Zoho Books Integration
                            </div>
                            <div style={{ fontSize: "12px", color: "#616a75", marginTop: "2px" }}>
                                Shopify Store: <strong style={{ color: "#202223" }}>{shopDomain}</strong>
                            </div>
                        </div>
                    </div>

                    {renderHeaderBadge()}
                </header>

                {/* DISCONNECTED WARNING BANNER WITH DIRECT CONNECT BUTTON */}
                {effectiveStatus === "disconnected" && (
                    <div
                        style={{
                            backgroundColor: "#fff8e6",
                            borderBottom: "1px solid #ffe58f",
                            padding: "10px 24px",
                            display: "flex",
                            alignItems: "center",
                            justifyContent: "space-between",
                            fontSize: "13px",
                            color: "#8c6b00",
                        }}
                    >
                        <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
                            <span style={{ fontSize: "16px" }}>⚠️</span>
                            <span>
                                <strong>Zoho Books is not connected.</strong> Connect your Zoho Books account to start synchronizing products, orders, and customers.
                            </span>
                        </div>
                        <button
                            type="button"
                            onClick={handleConnectZoho}
                            disabled={connecting}
                            style={{
                                backgroundColor: "#005bd3",
                                color: "#ffffff",
                                border: "none",
                                borderRadius: "6px",
                                padding: "6px 14px",
                                fontSize: "12px",
                                fontWeight: 600,
                                cursor: connecting ? "wait" : "pointer",
                                opacity: connecting ? 0.7 : 1,
                            }}
                        >
                            {connecting ? "Connecting..." : "Connect Now"}
                        </button>
                    </div>
                )}

                {/* MAIN CONTENT AREA */}
                <main style={{ flex: 1, padding: "20px 24px", boxSizing: "border-box", minWidth: 0, width: "100%", margin: "0 auto" }}>
                    {children}
                </main>
            </div>
        </AppProvider>
    );
}
