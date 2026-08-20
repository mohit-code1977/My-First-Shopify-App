import React, { useEffect, useState, useCallback } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

function formatRelativeTime(dateString) {
    if (!dateString) return "Never";
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return "Never";
    const now = new Date();
    const diffSec = Math.floor((now - date) / 1000);
    if (diffSec < 60) return "Just now";
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
    return date.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function StatusBadge({ status = "healthy", labelCustom }) {
    const isSuccess =
        status === "healthy" || status === "success" || status === "synced";
    const isWarning = status === "warning";

    let bg = "#eafbdf";
    let color = "#108043";
    let border = "#b7eb8f";

    if (isWarning) {
        bg = "#fff8e6";
        color = "#8c6b00";
        border = "#ffe58f";
    } else if (!isSuccess) {
        bg = "#fef3f2";
        color = "#d72c0d";
        border = "#fca5a5";
    }

    return (
        <span
            style={{
                display: "inline-flex",
                alignItems: "center",
                gap: "5px",
                padding: "3px 10px",
                borderRadius: "12px",
                fontSize: "12px",
                fontWeight: 600,
                background: bg,
                color: color,
                border: `1px solid ${border}`,
            }}
        >
            <span
                style={{
                    width: "6px",
                    height: "6px",
                    borderRadius: "50%",
                    background: color,
                }}
            />
            {labelCustom || (isSuccess ? "Synced" : "Failed")}
        </span>
    );
}

export default function Dashboard({
    shopDomain: propShopDomain,
    host,
    zohoConnected: initialConnected,
}) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reauthorizing, setReauthorizing] = useState(false);
    const [actionMessage, setActionMessage] = useState(null);

    const activeShopDomain = propShopDomain || "app-dev-kpaqieri.myshopify.com";

    const fetchDashboardData = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams();
            if (activeShopDomain) params.append("shop", activeShopDomain);
            if (host) params.append("host", host);

            const res = await fetch(
                `/api/zoho/dashboard?${params.toString()}`,
                {
                    headers: { Accept: "application/json" },
                },
            );

            if (!res.ok) {
                throw new Error("Unable to retrieve dashboard metrics.");
            }

            const json = await res.json();
            if (!json.success) {
                throw new Error(
                    json.message || "Failed to load dashboard data.",
                );
            }
            setData(json);
        } catch (err) {
            console.error("Dashboard fetch error:", err);
            setError("Unable to retrieve sync metrics.");
        } finally {
            setLoading(false);
        }
    }, [activeShopDomain, host]);

    useEffect(() => {
        fetchDashboardData();
    }, [fetchDashboardData]);

    const handleReauthorize = async () => {
        setReauthorizing(true);
        setActionMessage(null);
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
                body: JSON.stringify({
                    host,
                    shop: activeShopDomain,
                    reconsent: true,
                }),
            });

            const resData = await response.json().catch(() => ({}));

            if (response.ok && resData.success && resData.redirect_url) {
                const width = 600;
                const height = 700;
                const left = Math.max(
                    0,
                    Math.floor((window.screen.width - width) / 2),
                );
                const top = Math.max(
                    0,
                    Math.floor((window.screen.height - height) / 2),
                );

                const popup = window.open(
                    resData.redirect_url,
                    "ZohoOAuthPopup",
                    `width=${width},height=${height},left=${left},top=${top},scrollbars=yes,status=yes,resizable=yes`,
                );

                if (
                    !popup ||
                    popup.closed ||
                    typeof popup.closed === "undefined"
                ) {
                    window.location.href = resData.redirect_url;
                }
            } else {
                setActionMessage(
                    "Zoho reauthorization could not be started. Please try again.",
                );
            }
        } catch (err) {
            console.error("Reauthorization error:", err);
            setActionMessage(
                "Zoho reauthorization could not be started. Please try again.",
            );
        } finally {
            setReauthorizing(false);
        }
    };

    const isConnected = data?.connected ?? initialConnected ?? false;
    const connection = data?.zohoConnection || {};
    const shopInfo = data?.shop || {};
    const priceList = data?.priceList || {};
    const stats = data?.stats || {};
    const syncHealth = data?.syncHealth || {};
    const recentActivity = (data?.recentActivity || []).slice(0, 8);

    const failedCount = stats.failed_total || 0;

    // Operational status calculation
    const systemStatusText = !isConnected
        ? "Zoho Disconnected"
        : error
          ? "Dashboard Data Unavailable"
          : failedCount > 0
            ? `${failedCount} item${failedCount > 1 ? "s" : ""} require attention`
            : "All systems operational";

    const systemStatusBadge =
        !isConnected || error
            ? "failed"
            : failedCount > 0
              ? "warning"
              : "healthy";

    const importantEntities = [
        { key: "products", label: "Products", icon: "📦" },
        { key: "orders", label: "Orders", icon: "🛒" },
        { key: "inventory", label: "Inventory", icon: "📊" },
        { key: "payments", label: "Payments", icon: "💳" },
    ];

    return (
        <ZohoLayout
            activeTab="dashboard"
            shopDomain={activeShopDomain}
            host={host}
            zohoConnected={isConnected}
        >
            <div
                style={{
                    maxWidth: "1050px",
                    margin: "0 auto",
                    padding: "12px 8px 32px",
                    fontFamily:
                        "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif",
                }}
            >
                {/* 1. COMPACT CONNECTION SUMMARY HEADER */}
                <section
                    style={{
                        background: "#ffffff",
                        borderRadius: "8px",
                        border: "1px solid #e1e3e5",
                        padding: "16px 20px",
                        marginBottom: "16px",
                    }}
                >
                    <div
                        style={{
                            display: "flex",
                            flexWrap: "wrap",
                            justifyContent: "space-between",
                            alignItems: "center",
                            gap: "12px",
                            marginBottom: "12px",
                        }}
                    >
                        <div
                            style={{
                                display: "flex",
                                alignItems: "center",
                                gap: "10px",
                            }}
                        >
                            <h1
                                style={{
                                    fontSize: "20px",
                                    fontWeight: 700,
                                    color: "#1a1d20",
                                    margin: 0,
                                }}
                            >
                                Zoho Books Integration
                            </h1>
                            <StatusBadge
                                status={isConnected ? "healthy" : "failed"}
                                labelCustom={
                                    isConnected ? "Connected" : "Disconnected"
                                }
                            />
                            <StatusBadge
                                status={systemStatusBadge}
                                labelCustom={systemStatusText}
                            />
                        </div>

                        <div style={{ display: "flex", gap: "8px" }}>
                            <button
                                onClick={handleReauthorize}
                                disabled={reauthorizing}
                                style={{
                                    padding: "6px 12px",
                                    borderRadius: "6px",
                                    border: "none",
                                    background: "#008060",
                                    fontSize: "12px",
                                    fontWeight: 600,
                                    color: "#ffffff",
                                    cursor: reauthorizing ? "wait" : "pointer",
                                    opacity: reauthorizing ? 0.7 : 1,
                                }}
                            >
                                {reauthorizing
                                    ? "Opening OAuth..."
                                    : "Reauthorize Zoho"}
                            </button>
                            <button
                                onClick={fetchDashboardData}
                                disabled={loading}
                                style={{
                                    padding: "6px 12px",
                                    borderRadius: "6px",
                                    border: "1px solid #c9cccf",
                                    background: "#ffffff",
                                    fontSize: "12px",
                                    fontWeight: 600,
                                    color: "#202223",
                                    cursor: "pointer",
                                }}
                            >
                                {loading ? "Refreshing..." : "Refresh"}
                            </button>
                        </div>
                    </div>

                    {/* Connection Telemetry Row */}
                    <div
                        style={{
                            display: "flex",
                            flexWrap: "wrap",
                            alignItems: "center",
                            gap: "10px 20px",
                            fontSize: "12px",
                            background: "#f8f9fa",
                            padding: "10px 14px",
                            borderRadius: "6px",
                            color: "#616a75",
                        }}
                    >
                        <div>
                            Zoho Account:{" "}
                            <strong style={{ color: "#1a1d20" }}>
                                {connection.account_identifier ||
                                    "admin@zoho.com"}
                            </strong>
                        </div>
                        <div>
                            Organization:{" "}
                            <strong style={{ color: "#1a1d20", whiteSpace: "nowrap" }}>
                                {connection.organization_name ||
                                    "Shopify Zoho Integration Demo"}
                            </strong>
                        </div>
                        <div>
                            Org ID:{" "}
                            <strong style={{ color: "#1a1d20" }}>
                                {connection.organization_id || "60082438046"}
                            </strong>
                        </div>
                        <div>
                            Region:{" "}
                            <strong style={{ color: "#1a1d20" }}>
                                {connection.region || "India"}
                            </strong>
                        </div>
                        <div>
                            Datacenter:{" "}
                            <strong style={{ color: "#1a1d20" }}>
                                {connection.datacenter || "zohoapis.in"}
                            </strong>
                        </div>
                        <div>
                            Currency:{" "}
                            <strong style={{ color: "#1a1d20" }}>
                                {priceList.shopify_currency || shopInfo.currency || "USD"}
                            </strong>
                        </div>
                    </div>
                </section>

                {/* Friendly Error Toast */}
                {actionMessage && (
                    <div
                        style={{
                            padding: "10px 14px",
                            borderRadius: "6px",
                            background: "#fef3f2",
                            border: "1px solid #fca5a5",
                            color: "#d72c0d",
                            fontSize: "13px",
                            fontWeight: 500,
                            marginBottom: "16px",
                            display: "flex",
                            justifyContent: "space-between",
                            alignItems: "center",
                        }}
                    >
                        <span>⚠️ {actionMessage}</span>
                        <button
                            onClick={() => setActionMessage(null)}
                            style={{
                                background: "none",
                                border: "none",
                                color: "#d72c0d",
                                fontWeight: 700,
                                cursor: "pointer",
                            }}
                        >
                            ✕
                        </button>
                    </div>
                )}

                {error && (
                    <div
                        style={{
                            padding: "10px 14px",
                            borderRadius: "6px",
                            background: "#fff5f5",
                            border: "1px solid #fed7d7",
                            color: "#c53030",
                            fontSize: "13px",
                            marginBottom: "16px",
                        }}
                    >
                        {error}
                    </div>
                )}

                {/* 2. KEY METRICS (3 Cards) */}
                <section style={{ marginBottom: "16px" }}>
                    <div
                        style={{
                            display: "grid",
                            gridTemplateColumns:
                                "repeat(auto-fit, minmax(220px, 1fr))",
                            gap: "12px",
                        }}
                    >
                        <div
                            style={{
                                background: "#ffffff",
                                padding: "14px 16px",
                                borderRadius: "8px",
                                border: "1px solid #e1e3e5",
                            }}
                        >
                            <div
                                style={{
                                    fontSize: "12px",
                                    color: "#616a75",
                                    fontWeight: 600,
                                    textTransform: "uppercase",
                                }}
                            >
                                Products Synced
                            </div>
                            <div
                                style={{
                                    fontSize: "22px",
                                    fontWeight: 700,
                                    color: "#1a1d20",
                                    marginTop: "4px",
                                }}
                            >
                                {stats.products?.synced ?? 0}{" "}
                                <span
                                    style={{
                                        fontSize: "13px",
                                        color: "#8c9196",
                                        fontWeight: 500,
                                    }}
                                >
                                    / {stats.products?.total_variants ?? 0}
                                </span>
                            </div>
                        </div>

                        <div
                            style={{
                                background: "#ffffff",
                                padding: "14px 16px",
                                borderRadius: "8px",
                                border: "1px solid #e1e3e5",
                            }}
                        >
                            <div
                                style={{
                                    fontSize: "12px",
                                    color: "#616a75",
                                    fontWeight: 600,
                                    textTransform: "uppercase",
                                }}
                            >
                                Orders Synced
                            </div>
                            <div
                                style={{
                                    fontSize: "22px",
                                    fontWeight: 700,
                                    color: "#1070e0",
                                    marginTop: "4px",
                                }}
                            >
                                {stats.orders?.synced ?? 0}{" "}
                                <span
                                    style={{
                                        fontSize: "13px",
                                        color: "#8c9196",
                                        fontWeight: 500,
                                    }}
                                >
                                    / {stats.orders?.total ?? 0}
                                </span>
                            </div>
                        </div>

                        <div
                            style={{
                                background: "#ffffff",
                                padding: "14px 16px",
                                borderRadius: "8px",
                                border: "1px solid #e1e3e5",
                            }}
                        >
                            <div
                                style={{
                                    fontSize: "12px",
                                    color: "#616a75",
                                    fontWeight: 600,
                                    textTransform: "uppercase",
                                }}
                            >
                                Failed Syncs
                            </div>
                            <div
                                style={{
                                    fontSize: "22px",
                                    fontWeight: 700,
                                    color:
                                        failedCount > 0 ? "#d72c0d" : "#108043",
                                    marginTop: "4px",
                                }}
                            >
                                {failedCount}
                            </div>
                        </div>
                    </div>
                </section>

                {/* 3. SYNC HEALTH (4 Important Entities Only) */}
                <section style={{ marginBottom: "16px" }}>
                    <div
                        style={{
                            background: "#ffffff",
                            borderRadius: "8px",
                            border: "1px solid #e1e3e5",
                            padding: "14px 16px",
                        }}
                    >
                        <h2
                            style={{
                                fontSize: "14px",
                                fontWeight: 700,
                                color: "#1a1d20",
                                margin: "0 0 10px",
                            }}
                        >
                            Sync Health
                        </h2>
                        <div
                            style={{
                                display: "grid",
                                gridTemplateColumns:
                                    "repeat(auto-fit, minmax(190px, 1fr))",
                                gap: "10px",
                            }}
                        >
                            {importantEntities.map(({ key, label, icon }) => {
                                const health = syncHealth[key] || {};
                                const synced = health.synced ?? 0;
                                const count = health.count ?? 0;
                                const failed = health.failed ?? 0;
                                return (
                                    <div
                                        key={key}
                                        style={{
                                            background: "#f8f9fa",
                                            padding: "10px 12px",
                                            borderRadius: "6px",
                                        }}
                                    >
                                        <div
                                            style={{
                                                display: "flex",
                                                justifyContent: "space-between",
                                                alignItems: "center",
                                                marginBottom: "4px",
                                            }}
                                        >
                                            <span
                                                style={{
                                                    fontSize: "12px",
                                                    fontWeight: 600,
                                                    color: "#1a1d20",
                                                }}
                                            >
                                                {icon} {label}
                                            </span>
                                            <StatusBadge
                                                status={
                                                    failed > 0
                                                        ? "failed"
                                                        : "healthy"
                                                }
                                                labelCustom={
                                                    failed > 0
                                                        ? `${failed} Failed`
                                                        : "Healthy"
                                                }
                                            />
                                        </div>
                                        <div
                                            style={{
                                                fontSize: "15px",
                                                fontWeight: 700,
                                                color: "#1a1d20",
                                            }}
                                        >
                                            {synced}{" "}
                                            <span
                                                style={{
                                                    fontSize: "11px",
                                                    color: "#8c9196",
                                                    fontWeight: 400,
                                                }}
                                            >
                                                / {count} synced
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </section>

                {/* 4. RECENT ACTIVITY & 5. CURRENCY SUMMARY GRID */}
                <div
                    style={{
                        display: "grid",
                        gridTemplateColumns:
                            "repeat(auto-fit, minmax(320px, 1fr))",
                        gap: "14px",
                        marginBottom: "16px",
                    }}
                >
                    {/* Recent Activity */}
                    <div
                        style={{
                            background: "#ffffff",
                            borderRadius: "8px",
                            border: "1px solid #e1e3e5",
                            padding: "14px 16px",
                        }}
                    >
                        <h2
                            style={{
                                fontSize: "14px",
                                fontWeight: 700,
                                color: "#1a1d20",
                                margin: "0 0 10px",
                            }}
                        >
                            Recent Activity
                        </h2>
                        {recentActivity.length === 0 ? (
                            <div
                                style={{
                                    fontSize: "12px",
                                    color: "#8c9196",
                                    padding: "12px 0",
                                    textAlign: "center",
                                }}
                            >
                                No recent activity records.
                            </div>
                        ) : (
                            <div
                                style={{
                                    display: "flex",
                                    flexDirection: "column",
                                    gap: "6px",
                                }}
                            >
                                {recentActivity.map((entry) => (
                                    <div
                                        key={entry.id}
                                        style={{
                                            display: "flex",
                                            justifyContent: "space-between",
                                            alignItems: "center",
                                            padding: "6px 8px",
                                            background: "#f8f9fa",
                                            borderRadius: "4px",
                                            fontSize: "12px",
                                        }}
                                    >
                                        <div
                                            style={{
                                                display: "flex",
                                                gap: "6px",
                                                alignItems: "center",
                                            }}
                                        >
                                            <span
                                                style={{
                                                    fontWeight: 600,
                                                    color: "#1a1d20",
                                                    textTransform: "capitalize",
                                                }}
                                            >
                                                {entry.entity_type}{" "}
                                                {entry.action}
                                            </span>
                                        </div>
                                        <div
                                            style={{
                                                display: "flex",
                                                gap: "6px",
                                                alignItems: "center",
                                            }}
                                        >
                                            <StatusBadge
                                                status={entry.status}
                                            />
                                            <span
                                                style={{
                                                    fontSize: "11px",
                                                    color: "#8c9196",
                                                }}
                                            >
                                                {formatRelativeTime(
                                                    entry.created_at,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Currency & Pricing Summary */}
                    <div
                        style={{
                            background: "#ffffff",
                            borderRadius: "8px",
                            border: "1px solid #e1e3e5",
                            padding: "14px 16px",
                        }}
                    >
                        <h2
                            style={{
                                fontSize: "14px",
                                fontWeight: 700,
                                color: "#1a1d20",
                                margin: "0 0 10px",
                            }}
                        >
                            Currency & Pricing
                        </h2>
                        <div
                            style={{
                                display: "flex",
                                flexDirection: "column",
                                gap: "6px",
                                fontSize: "12px",
                                marginBottom: "10px",
                            }}
                        >
                            <div
                                style={{
                                    display: "flex",
                                    justifyContent: "space-between",
                                    padding: "6px 8px",
                                    background: "#f8f9fa",
                                    borderRadius: "4px",
                                }}
                            >
                                <span style={{ color: "#616a75" }}>
                                    Shopify Currency:
                                </span>
                                <strong style={{ color: "#1a1d20" }}>
                                    {priceList.shopify_currency ||
                                        shopInfo.currency ||
                                        "USD"}
                                </strong>
                            </div>
                            <div
                                style={{
                                    display: "flex",
                                    justifyContent: "space-between",
                                    padding: "6px 8px",
                                    background: "#f8f9fa",
                                    borderRadius: "4px",
                                }}
                            >
                                <span style={{ color: "#616a75" }}>
                                    Zoho Base Currency:
                                </span>
                                <strong style={{ color: "#1a1d20" }}>
                                    {priceList.zoho_base_currency || "INR"}
                                </strong>
                            </div>
                            <div
                                style={{
                                    display: "flex",
                                    justifyContent: "space-between",
                                    padding: "6px 8px",
                                    background: "#f8f9fa",
                                    borderRadius: "4px",
                                }}
                            >
                                <span style={{ color: "#616a75" }}>
                                    Transaction Price List:
                                </span>
                                <strong style={{ color: "#008060" }}>
                                    {priceList.active_price_list_name ||
                                        "Shopify USD Price List"}
                                </strong>
                            </div>
                            <div
                                style={{
                                    display: "flex",
                                    justifyContent: "space-between",
                                    padding: "6px 8px",
                                    background: "#f8f9fa",
                                    borderRadius: "4px",
                                }}
                            >
                                <span style={{ color: "#616a75" }}>
                                    Price List Status:
                                </span>
                                <StatusBadge
                                    status="healthy"
                                    labelCustom="Active"
                                />
                            </div>
                        </div>
                        <div
                            style={{
                                fontSize: "11px",
                                color: "#616a75",
                                background: "#f1f2f4",
                                padding: "6px 8px",
                                borderRadius: "4px",
                            }}
                        >
                            ℹ️ Shopify transaction pricing is preserved in{" "}
                            {priceList.shopify_currency ||
                                shopInfo.currency ||
                                "USD"}
                            .
                        </div>
                    </div>
                </div>
            </div>
        </ZohoLayout>
    );
}
