import React, { useEffect, useState, useRef } from "react";
import ZohoLayout from "@/Layouts/ZohoLayout";

const CUSTOMERS_DATA_URL = "/api/zoho/customers";
const SYNC_CUSTOMER_URL = "/zoho/sync-customer";
const BULK_SYNC_CUSTOMERS_URL = "/zoho/bulk-sync-customers";

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

    // Bulk Selection State
    const [selectedIds, setSelectedIds] = useState([]);
    const [bulkSyncing, setBulkSyncing] = useState(false);
    const [bulkResultsModal, setBulkResultsModal] = useState(null);

    const headerCheckboxRef = useRef(null);

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

    const visibleIds = filteredCustomers.map((c) => c.id);
    const isAllSelected =
        visibleIds.length > 0 &&
        visibleIds.every((id) => selectedIds.includes(id));
    const isIndeterminate =
        selectedIds.length > 0 && !isAllSelected;

    useEffect(() => {
        if (headerCheckboxRef.current) {
            headerCheckboxRef.current.indeterminate = isIndeterminate;
        }
    }, [isIndeterminate]);

    const handleToggleSelectAll = () => {
        if (isAllSelected) {
            setSelectedIds((prev) =>
                prev.filter((id) => !visibleIds.includes(id))
            );
        } else {
            const combined = Array.from(
                new Set([...selectedIds, ...visibleIds])
            );
            setSelectedIds(combined);
        }
    };

    const handleToggleSelectRow = (id) => {
        setSelectedIds((prev) =>
            prev.includes(id)
                ? prev.filter((item) => item !== id)
                : [...prev, id]
        );
    };

    const handleBulkSync = async () => {
        if (selectedIds.length === 0) return;

        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setBulkSyncing(true);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(BULK_SYNC_CUSTOMERS_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({
                    customer_ids: selectedIds,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setBulkResultsModal({
                    summary: data.summary || {},
                    results: data.results || [],
                });
                await loadData();
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Bulk customer sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during bulk customer sync.",
            });
        } finally {
            setBulkSyncing(false);
        }
    };

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

                {/* BULK ACTION TOOLBAR */}
                {selectedIds.length > 0 && (
                    <div
                        style={{
                            backgroundColor: "#002040",
                            color: "#ffffff",
                            borderRadius: "8px",
                            padding: "12px 20px",
                            display: "flex",
                            justifyContent: "space-between",
                            alignItems: "center",
                            boxShadow: "0 2px 8px rgba(0,0,0,0.15)",
                        }}
                    >
                        <div style={{ display: "flex", alignItems: "center", gap: "12px" }}>
                            <span style={{ fontSize: "14px", fontWeight: 600 }}>
                                ✓ {selectedIds.length} customer(s) selected
                            </span>
                            <button
                                type="button"
                                onClick={() => setSelectedIds([])}
                                style={{
                                    background: "none",
                                    border: "none",
                                    color: "#99ccee",
                                    fontSize: "13px",
                                    cursor: "pointer",
                                    textDecoration: "underline",
                                }}
                            >
                                Deselect all
                            </button>
                        </div>

                        <div style={{ display: "flex", gap: "10px" }}>
                            <button
                                type="button"
                                onClick={handleBulkSync}
                                disabled={bulkSyncing}
                                style={{
                                    padding: "8px 16px",
                                    borderRadius: "6px",
                                    border: "none",
                                    backgroundColor: "#008060",
                                    color: "#ffffff",
                                    fontSize: "13px",
                                    fontWeight: 600,
                                    cursor: bulkSyncing ? "wait" : "pointer",
                                }}
                            >
                                {bulkSyncing ? "Syncing..." : "Sync Selected Customers"}
                            </button>
                        </div>
                    </div>
                )}

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
                                <th style={{ padding: "12px 16px", width: "40px" }}>
                                    <input
                                        type="checkbox"
                                        ref={headerCheckboxRef}
                                        checked={isAllSelected}
                                        onChange={handleToggleSelectAll}
                                        style={{ cursor: "pointer", width: "16px", height: "16px" }}
                                    />
                                </th>
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
                                        colSpan={8}
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
                                    const isSelected = selectedIds.includes(c.id);

                                    return (
                                        <tr
                                            key={c.id}
                                            style={{
                                                borderBottom:
                                                    "1px solid #f1f2f4",
                                                backgroundColor: isSelected ? "#f4f6f8" : "transparent",
                                            }}
                                        >
                                            <td style={{ padding: "12px 16px", width: "40px" }}>
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    onChange={() => handleToggleSelectRow(c.id)}
                                                    style={{ cursor: "pointer", width: "16px", height: "16px" }}
                                                />
                                            </td>
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
                                                    disabled={isSyncing || bulkSyncing}
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
                                        colSpan={8}
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

                {/* BULK RESULTS MODAL */}
                {bulkResultsModal && (
                    <div
                        style={{
                            position: "fixed",
                            top: 0,
                            left: 0,
                            right: 0,
                            bottom: 0,
                            backgroundColor: "rgba(0,0,0,0.5)",
                            display: "flex",
                            justifyContent: "center",
                            alignItems: "center",
                            zIndex: 1000,
                        }}
                    >
                        <div
                            style={{
                                backgroundColor: "#ffffff",
                                borderRadius: "12px",
                                padding: "24px",
                                width: "90%",
                                maxWidth: "600px",
                                maxHeight: "80vh",
                                overflowY: "auto",
                                boxShadow: "0 4px 20px rgba(0,0,0,0.2)",
                            }}
                        >
                            <h2 style={{ fontSize: "18px", fontWeight: 700, margin: "0 0 12px 0" }}>
                                Bulk Synchronization Results
                            </h2>

                            <div style={{ display: "flex", gap: "10px", marginBottom: "16px" }}>
                                <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#e4e5e7", color: "#202223" }}>
                                    Total: {bulkResultsModal.summary?.total || 0}
                                </span>
                                <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#eafbdf", color: "#108043" }}>
                                    Synced: {bulkResultsModal.summary?.synced || 0}
                                </span>
                                <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#fbeae8", color: "#d72c0d" }}>
                                    Failed: {bulkResultsModal.summary?.failed || 0}
                                </span>
                                {bulkResultsModal.summary?.skipped > 0 && (
                                    <span style={{ padding: "4px 12px", borderRadius: "12px", fontSize: "12px", fontWeight: 600, backgroundColor: "#fff8e6", color: "#b78103" }}>
                                        Skipped: {bulkResultsModal.summary?.skipped}
                                    </span>
                                )}
                            </div>

                            <div style={{ border: "1px solid #e1e3e5", borderRadius: "8px", overflow: "hidden", marginBottom: "20px" }}>
                                <table style={{ width: "100%", borderCollapse: "collapse", fontSize: "13px" }}>
                                    <thead>
                                        <tr style={{ backgroundColor: "#f8f9fa", borderBottom: "1px solid #e1e3e5", textAlign: "left" }}>
                                            <th style={{ padding: "10px 14px" }}>Customer / ID</th>
                                            <th style={{ padding: "10px 14px" }}>Status</th>
                                            <th style={{ padding: "10px 14px" }}>Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {bulkResultsModal.results?.map((res, idx) => (
                                            <tr key={idx} style={{ borderBottom: "1px solid #f1f2f4" }}>
                                                <td style={{ padding: "10px 14px", fontWeight: 600 }}>
                                                    {res.name || `ID #${res.id}`}
                                                </td>
                                                <td style={{ padding: "10px 14px" }}>
                                                    <span
                                                        style={{
                                                            padding: "2px 8px",
                                                            borderRadius: "10px",
                                                            fontSize: "11px",
                                                            fontWeight: 600,
                                                            backgroundColor:
                                                                res.status === "success"
                                                                    ? "#eafbdf"
                                                                    : res.status === "skipped"
                                                                    ? "#fff8e6"
                                                                    : "#fbeae8",
                                                            color:
                                                                res.status === "success"
                                                                    ? "#108043"
                                                                    : res.status === "skipped"
                                                                    ? "#b78103"
                                                                    : "#d72c0d",
                                                        }}
                                                    >
                                                        {res.status}
                                                    </span>
                                                </td>
                                                <td style={{ padding: "10px 14px", color: "#616a75" }}>
                                                    {res.message}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div style={{ textAlign: "right" }}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setBulkResultsModal(null);
                                        setSelectedIds([]);
                                    }}
                                    style={{
                                        padding: "8px 18px",
                                        borderRadius: "6px",
                                        border: "none",
                                        backgroundColor: "#202223",
                                        color: "#ffffff",
                                        fontSize: "13px",
                                        fontWeight: 600,
                                        cursor: "pointer",
                                    }}
                                >
                                    Close & Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ZohoLayout>
    );
}
