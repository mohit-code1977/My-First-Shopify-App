import React, { useEffect, useState } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

const ORDERS_DATA_URL = "/api/zoho/orders";
const SYNC_ORDER_URL = "/zoho/sync-order";
const SYNC_INVOICE_URL = "/zoho/sync-invoice";

export default function Orders({
    shop,
    orders = [],
    zohoConnected = false,
    host = "",
}) {
    const [loading, setLoading] = useState(true);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [orderList, setOrderList] = useState(orders || []);
    const [search, setSearch] = useState("");
    const [filterStatus, setFilterStatus] = useState("all");
    const [syncingOrderId, setSyncingOrderId] = useState(null);
    const [syncType, setSyncType] = useState(null);
    const [notification, setNotification] = useState(null);

    const loadData = async () => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(ORDERS_DATA_URL, { headers });
            const data = await response.json();

            if (response.ok && data.success) {
                setOrderList(data.orders || []);
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean")
                    setConnectedState(data.zohoConnected);
            }
        } catch (error) {
            console.error("Failed to load orders:", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    const handleSyncOrder = async (orderId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message:
                    "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingOrderId(orderId);
        setSyncType("order");
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_ORDER_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ order_id: orderId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message:
                        data.message ||
                        "Sales Order synchronized successfully.",
                });
                await loadData();
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Sales Order creation failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during Sales Order sync.",
            });
        } finally {
            setSyncingOrderId(null);
            setSyncType(null);
        }
    };

    const handleSyncInvoice = async (orderId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message:
                    "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingOrderId(orderId);
        setSyncType("invoice");
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_INVOICE_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ order_id: orderId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message:
                        data.message ||
                        "Invoice created/synchronized successfully.",
                });
                await loadData();
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Invoice creation failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during invoice sync.",
            });
        } finally {
            setSyncingOrderId(null);
            setSyncType(null);
        }
    };

    const filteredOrders = orderList.filter((o) => {
        const orderNum = (o.name || o.shopify_order_number || "")
            .toString()
            .toLowerCase();
        const custName = (
            o.customer
                ? `${o.customer.first_name || ""} ${o.customer.last_name || ""}`
                : ""
        ).toLowerCase();

        const matchesSearch =
            orderNum.includes(search.toLowerCase()) ||
            custName.includes(search.toLowerCase());
        if (!matchesSearch) return false;

        const hasInvoice = o.invoice && o.invoice.zoho_invoice_id;
        if (filterStatus === "invoiced") return hasInvoice;
        if (filterStatus === "pending") return !hasInvoice;
        return true;
    });

    return (
        <ZohoLayout
            title="Orders & Invoices | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="orders"
        >
            <div
                style={{
                    display: "flex",
                    flexDirection: "column",
                    gap: "20px",
                }}
            >
                {/* NOTIFICATION */}
                {notification && (
                    <div
                        style={{
                            padding: "12px 16px",
                            borderRadius: "8px",
                            fontSize: "14px",
                            fontWeight: 500,
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

                {/* HEADER */}
                <div
                    style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                    }}
                >
                    <div>
                        <h1
                            style={{
                                fontSize: "24px",
                                fontWeight: 700,
                                color: "#1a1d20",
                                margin: 0,
                            }}
                        >
                            Orders & Invoices
                        </h1>
                        <p
                            style={{
                                fontSize: "14px",
                                color: "#616a75",
                                margin: "4px 0 0 0",
                            }}
                        >
                            Manage Shopify orders and synchronize them as Sales
                            Orders & Invoices in Zoho Books.
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
                    <div style={{ display: "flex", gap: "8px" }}>
                        {[
                            {
                                key: "all",
                                label: `All Orders (${orderList.length})`,
                            },
                            {
                                key: "invoiced",
                                label: `Invoiced (${orderList.filter((o) => o.invoice?.zoho_invoice_id).length})`,
                            },
                            {
                                key: "pending",
                                label: `Pending Invoice (${orderList.filter((o) => !o.invoice?.zoho_invoice_id).length})`,
                            },
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
                                    fontWeight:
                                        filterStatus === tab.key ? 600 : 500,
                                    backgroundColor:
                                        filterStatus === tab.key
                                            ? "#202223"
                                            : "#f1f2f4",
                                    color:
                                        filterStatus === tab.key
                                            ? "#ffffff"
                                            : "#616a75",
                                    cursor: "pointer",
                                }}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    <input
                        type="text"
                        placeholder="Search order #, customer..."
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

                {/* ORDERS TABLE */}
                <div
                    style={{
                        backgroundColor: "#ffffff",
                        borderRadius: "10px",
                        border: "1px solid #e1e3e5",
                        overflow: "hidden",
                    }}
                >
                    <table
                        style={{
                            width: "100%",
                            borderCollapse: "collapse",
                            fontSize: "13px",
                        }}
                    >
                        <thead>
                            <tr
                                style={{
                                    backgroundColor: "#f8f9fa",
                                    borderBottom: "1px solid #e1e3e5",
                                    textAlign: "left",
                                    color: "#616a75",
                                }}
                            >
                                <th style={{ padding: "12px 16px" }}>
                                    ORDER #
                                </th>
                                <th style={{ padding: "12px 16px" }}>
                                    CUSTOMER
                                </th>
                                <th style={{ padding: "12px 16px" }}>DATE</th>
                                <th style={{ padding: "12px 16px" }}>TOTAL</th>
                                <th style={{ padding: "12px 16px" }}>
                                    ZOHO SALES ORDER
                                </th>
                                <th style={{ padding: "12px 16px" }}>
                                    ZOHO INVOICE
                                </th>
                                <th style={{ padding: "12px 16px" }}>
                                    INVOICE STATUS
                                </th>
                                <th
                                    style={{
                                        padding: "12px 16px",
                                        textAlign: "right",
                                    }}
                                >
                                    ACTIONS
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td
                                        colSpan={8}
                                        style={{
                                            textAlign: "center",
                                            padding: "40px",
                                            color: "#616a75",
                                        }}
                                    >
                                        Loading orders...
                                    </td>
                                </tr>
                            ) : filteredOrders.length > 0 ? (
                                filteredOrders.map((o) => {
                                    const isSyncing = syncingOrderId === o.id;
                                    const hasInvoice =
                                        !!o.invoice?.zoho_invoice_id;
                                    const invoiceStatus =
                                        o.invoice?.status ||
                                        (hasInvoice ? "synced" : "not_created");

                                    return (
                                        <tr
                                            key={o.id}
                                            style={{
                                                borderBottom:
                                                    "1px solid #f1f2f4",
                                            }}
                                        >
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontWeight: 700,
                                                    color: "#005bd3",
                                                }}
                                            >
                                                {o.name || (o.order_number ? `#${o.order_number}` : `#${o.shopify_order_id}`)}
                                            </td>
                                            <td
                                                style={{ padding: "12px 16px" }}
                                            >
                                                {o.customer ? (
                                                    <div>
                                                        <div
                                                            style={{
                                                                fontWeight: 600,
                                                                color: "#1a1d20",
                                                            }}
                                                        >
                                                            {
                                                                o.customer
                                                                    .first_name
                                                            }{" "}
                                                            {
                                                                o.customer
                                                                    .last_name
                                                            }
                                                        </div>
                                                        <div
                                                            style={{
                                                                fontSize:
                                                                    "12px",
                                                                color: "#616a75",
                                                            }}
                                                        >
                                                            {o.customer.email}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span
                                                        style={{
                                                            color: "#8c9196",
                                                        }}
                                                    >
                                                        Guest Customer
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    color: "#616a75",
                                                }}
                                            >
                                                {o.order_date || o.created_at
                                                    ? new Date(
                                                          o.order_date || o.created_at,
                                                      ).toLocaleDateString()
                                                    : "—"}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontWeight: 600,
                                                }}
                                            >
                                                $
                                                {parseFloat(
                                                    o.total_price || 0,
                                                ).toFixed(2)}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontFamily: "monospace",
                                                    color: "#202223",
                                                }}
                                            >
                                                {o.zoho_sales_order_id || o.zoho_sales_order_number ? (
                                                    o.zoho_sales_order_number || `SO-${o.zoho_sales_order_id}`
                                                ) : (
                                                    <span
                                                        style={{
                                                            color: "#8c9196",
                                                        }}
                                                    >
                                                        Not Created
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontFamily: "monospace",
                                                    color: "#202223",
                                                }}
                                            >
                                                {o.invoice?.zoho_invoice_id ? (
                                                    `INV-${o.invoice.zoho_invoice_id}`
                                                ) : (
                                                    <span
                                                        style={{
                                                            color: "#8c9196",
                                                        }}
                                                    >
                                                        Not Created
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                style={{ padding: "12px 16px" }}
                                            >
                                                <span
                                                    style={{
                                                        padding: "3px 10px",
                                                        borderRadius: "12px",
                                                        fontSize: "11px",
                                                        fontWeight: 600,
                                                        backgroundColor:
                                                            hasInvoice
                                                                ? "#eafbdf"
                                                                : "#fff8e6",
                                                        color: hasInvoice
                                                            ? "#108043"
                                                            : "#b78103",
                                                        border: hasInvoice
                                                            ? "1px solid #b7eb8f"
                                                            : "1px solid #ffe58f",
                                                    }}
                                                >
                                                    {hasInvoice
                                                        ? "Invoiced"
                                                        : "Pending Invoice"}
                                                </span>
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    textAlign: "right",
                                                }}
                                            >
                                                <div
                                                    style={{
                                                        display: "flex",
                                                        gap: "6px",
                                                        justifyContent:
                                                            "flex-end",
                                                    }}
                                                >
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            handleSyncOrder(o.id)
                                                        }
                                                        disabled={isSyncing}
                                                        style={{
                                                            padding: "6px 12px",
                                                            borderRadius: "6px",
                                                            border: "1px solid #c9cccf",
                                                            backgroundColor:
                                                                "#ffffff",
                                                            fontSize: "12px",
                                                            fontWeight: 600,
                                                            color: "#202223",
                                                            cursor: isSyncing
                                                                ? "wait"
                                                                : "pointer",
                                                        }}
                                                    >
                                                        {isSyncing &&
                                                        syncType === "order"
                                                            ? "Syncing..."
                                                            : o.zoho_sales_order_id ||
                                                                o.zoho_sales_order_number
                                                              ? "Sync Order"
                                                              : "Sync Order"}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            handleSyncInvoice(
                                                                o.id,
                                                            )
                                                        }
                                                        disabled={isSyncing}
                                                        style={{
                                                            padding: "6px 12px",
                                                            borderRadius: "6px",
                                                            border: "1px solid #005bd3",
                                                            backgroundColor:
                                                                "#005bd3",
                                                            fontSize: "12px",
                                                            fontWeight: 600,
                                                            color: "#ffffff",
                                                            cursor: isSyncing
                                                                ? "wait"
                                                                : "pointer",
                                                        }}
                                                    >
                                                        {isSyncing &&
                                                        syncType === "invoice"
                                                            ? "Syncing..."
                                                            : hasInvoice
                                                              ? "Sync Again"
                                                              : "Sync Invoice"}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td
                                        colSpan={8}
                                        style={{
                                            textAlign: "center",
                                            padding: "40px",
                                            color: "#616a75",
                                        }}
                                    >
                                        No orders found matching your search.
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
