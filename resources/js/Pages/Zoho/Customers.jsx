import React, { useEffect, useState } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

const CUSTOMERS_DATA_URL = "/api/zoho/customers";
const SYNC_CUSTOMER_URL = "/zoho/sync-customer";

export default function Customers({
    shop,
    customers = [],
    zohoConnected = false,
    host = "",
}) {
    const [loading, setLoading] = useState(true);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [customerList, setCustomerList] = useState(customers || []);
    const [search, setSearch] = useState("");
    const [filterStatus, setFilterStatus] = useState("all");
    const [syncingCustomerId, setSyncingCustomerId] = useState(null);
    const [notification, setNotification] = useState(null);

    const loadData = async () => {
        setLoading(true);
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(CUSTOMERS_DATA_URL, { headers });
            const data = await response.json();

            if (response.ok && data.success) {
                setCustomerList(data.customers || []);
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean")
                    setConnectedState(data.zohoConnected);
            }
        } catch (error) {
            console.error("Failed to load customers:", error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    const handleSyncCustomer = async (customerId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message:
                    "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingCustomerId(customerId);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_CUSTOMER_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ customer_id: customerId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message:
                        data.message ||
                        "Customer synchronized to Zoho successfully.",
                });
                await loadData();
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Customer sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during customer sync.",
            });
        } finally {
            setSyncingCustomerId(null);
        }
    };

    const filteredCustomers = customerList.filter((c) => {
        const fullName =
            `${c.first_name || ""} ${c.last_name || ""}`.toLowerCase();
        const email = (c.email || "").toLowerCase();
        const phone = (c.phone || "").toLowerCase();

        const matchesSearch =
            fullName.includes(search.toLowerCase()) ||
            email.includes(search.toLowerCase()) ||
            phone.includes(search.toLowerCase());

        if (!matchesSearch) return false;

        const isSynced = !!c.zoho_contact_id;
        if (filterStatus === "synced") return isSynced;
        if (filterStatus === "not_synced") return !isSynced;
        return true;
    });

    return (
        <ZohoLayout
            title="Customers | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="customers"
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
                            Customers
                        </h1>
                        <p
                            style={{
                                fontSize: "14px",
                                color: "#616a75",
                                margin: "4px 0 0 0",
                            }}
                        >
                            Manage Shopify store customers and their Zoho Books
                            contact synchronization mapping.
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
                                label: `All Customers (${customerList.length})`,
                            },
                            {
                                key: "synced",
                                label: `Synced (${customerList.filter((c) => c.zoho_contact_id).length})`,
                            },
                            {
                                key: "not_synced",
                                label: `Not Synced (${customerList.filter((c) => !c.zoho_contact_id).length})`,
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
                        placeholder="Search name, email, phone..."
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

                {/* CUSTOMERS TABLE */}
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
                                    CUSTOMER
                                </th>
                                <th style={{ padding: "12px 16px" }}>EMAIL</th>
                                <th style={{ padding: "12px 16px" }}>PHONE</th>
                                <th style={{ padding: "12px 16px" }}>
                                    LOCATION
                                </th>
                                <th style={{ padding: "12px 16px" }}>
                                    ZOHO CONTACT ID
                                </th>
                                <th style={{ padding: "12px 16px" }}>STATUS</th>
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
                                        colSpan={7}
                                        style={{
                                            textAlign: "center",
                                            padding: "40px",
                                            color: "#616a75",
                                        }}
                                    >
                                        Loading customers...
                                    </td>
                                </tr>
                            ) : filteredCustomers.length > 0 ? (
                                filteredCustomers.map((c) => {
                                    const isSyncing =
                                        syncingCustomerId === c.id;
                                    const isSynced = !!c.zoho_contact_id;

                                    return (
                                        <tr
                                            key={c.id}
                                            style={{
                                                borderBottom:
                                                    "1px solid #f1f2f4",
                                            }}
                                        >
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontWeight: 600,
                                                    color: "#1a1d20",
                                                }}
                                            >
                                                {c.first_name || c.last_name
                                                    ? `${c.first_name || ""} ${c.last_name || ""}`
                                                    : "Guest Customer"}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    color: "#616a75",
                                                }}
                                            >
                                                {c.email || "—"}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    color: "#616a75",
                                                }}
                                            >
                                                {c.phone || "—"}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    color: "#616a75",
                                                }}
                                            >
                                                {(() => {
                                                    const addr = c.billing_address || c.shipping_address || {};
                                                    const city = addr.city || c.city;
                                                    const country = addr.country || addr.country_name || c.country;
                                                    if (city && country) return `${city}, ${country}`;
                                                    return city || country || "—";
                                                })()}
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    fontFamily: "monospace",
                                                    color: "#202223",
                                                }}
                                            >
                                                {c.zoho_contact_id || (
                                                    <span
                                                        style={{
                                                            color: "#8c9196",
                                                        }}
                                                    >
                                                        Not Linked
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
                                                            isSynced
                                                                ? "#eafbdf"
                                                                : "#fff8e6",
                                                        color: isSynced
                                                            ? "#108043"
                                                            : "#b78103",
                                                        border: isSynced
                                                            ? "1px solid #b7eb8f"
                                                            : "1px solid #ffe58f",
                                                    }}
                                                >
                                                    {isSynced
                                                        ? "Synced"
                                                        : "Not Synced"}
                                                </span>
                                            </td>
                                            <td
                                                style={{
                                                    padding: "12px 16px",
                                                    textAlign: "right",
                                                }}
                                            >
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        handleSyncCustomer(c.id)
                                                    }
                                                    disabled={isSyncing}
                                                    style={{
                                                        padding: "6px 14px",
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
                                                    {isSyncing
                                                        ? "Syncing..."
                                                        : isSynced
                                                          ? "Sync Again"
                                                          : "Sync Customer"}
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td
                                        colSpan={7}
                                        style={{
                                            textAlign: "center",
                                            padding: "40px",
                                            color: "#616a75",
                                        }}
                                    >
                                        No customers found matching your search.
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
