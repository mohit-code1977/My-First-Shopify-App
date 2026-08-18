import React, { useEffect, useState } from "react";
import {
    Page,
    Card,
    Tabs,
    IndexTable,
    Badge,
    Banner,
    Button,
    Popover,
    ActionList,
    TextField,
    Modal,
    Text,
    Box,
    BlockStack,
    InlineStack,
    useIndexResourceState,
} from "@shopify/polaris";
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
    const [selectedTab, setSelectedTab] = useState(0);
    const [syncingCustomerId, setSyncingCustomerId] = useState(null);
    const [notification, setNotification] = useState(null);
    const [openActionMenuId, setOpenActionMenuId] = useState(null);

    // Bulk Selection State
    const [bulkSyncing, setBulkSyncing] = useState(false);
    const [bulkResultsModal, setBulkResultsModal] = useState(null);

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
                message: "Zoho is not connected. Please connect in Settings first.",
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
                    message: data.message || "Customer synchronized to Zoho successfully.",
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

    const tabKeys = ["all", "synced", "not_synced"];
    const currentTabKey = tabKeys[selectedTab] || "all";

    const filteredCustomers = customerList.filter((c) => {
        const fullName = `${c.first_name || ""} ${c.last_name || ""}`.toLowerCase();
        const email = (c.email || "").toLowerCase();
        const phone = (c.phone || "").toLowerCase();

        const matchesSearch =
            fullName.includes(search.toLowerCase()) ||
            email.includes(search.toLowerCase()) ||
            phone.includes(search.toLowerCase());

        if (!matchesSearch) return false;

        const isSynced = !!c.zoho_contact_id;
        if (currentTabKey === "synced") return isSynced;
        if (currentTabKey === "not_synced") return !isSynced;
        return true;
    });

    const {
        selectedResources,
        allResourcesSelected,
        handleSelectionChange,
        clearSelection,
    } = useIndexResourceState(filteredCustomers);

    const handleBulkSync = async () => {
        if (selectedResources.length === 0) return;

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
                    customer_ids: selectedResources,
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

    const tabs = [
        { id: "all", content: `All (${customerList.length})` },
        {
            id: "synced",
            content: `Synced (${customerList.filter((c) => c.zoho_contact_id).length})`,
        },
        {
            id: "not_synced",
            content: `Pending Sync (${customerList.filter((c) => !c.zoho_contact_id).length})`,
        },
    ];

    const promotedBulkActions = [
        {
            content: "Sync Selected Customers",
            onAction: handleBulkSync,
            disabled: bulkSyncing,
        },
    ];

    const headings = [
        { title: "Customer Name" },
        { title: "Email" },
        { title: "Phone" },
        { title: "Zoho Contact ID" },
        { title: "Sync Status" },
        { title: "Action", alignment: "end" },
    ];

    const rowMarkup = filteredCustomers.map((c, index) => {
        const isSyncing = syncingCustomerId === c.id;
        const isSelected = selectedResources.includes(c.id);
        const isSynced = !!c.zoho_contact_id;
        const isMenuOpen = openActionMenuId === c.id;

        return (
            <IndexTable.Row
                id={c.id}
                key={c.id}
                selected={isSelected}
                position={index}
            >
                <IndexTable.Cell>
                    <Text variant="bodyMd" fontWeight="semibold" as="span">
                        {c.first_name || c.last_name
                            ? `${c.first_name || ""} ${c.last_name || ""}`.trim()
                            : "No Name"}
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Text variant="bodySm" tone="subdued" as="span">
                        {c.email || "—"}
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Text variant="bodySm" tone="subdued" as="span">
                        {c.phone || "—"}
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {c.zoho_contact_id ? (
                        <Text variant="bodySm" tone="subdued" as="span">
                            <code style={{ fontSize: "11px", backgroundColor: "#f1f2f4", padding: "2px 6px", borderRadius: "4px", color: "#616a75" }}>
                                {c.zoho_contact_id}
                            </code>
                        </Text>
                    ) : (
                        <Text variant="bodySm" tone="subdued" as="span">—</Text>
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Badge tone={isSynced ? "success" : "warning"}>
                        {isSynced ? "Synced" : "Pending Sync"}
                    </Badge>
                </IndexTable.Cell>
                <IndexTable.Cell alignment="end">
                    <div onClick={(e) => e.stopPropagation()}>
                        <Popover
                            active={isMenuOpen}
                            activator={
                                <Button
                                    size="slim"
                                    onClick={() => setOpenActionMenuId(isMenuOpen ? null : c.id)}
                                    disabled={isSyncing || bulkSyncing}
                                    disclosure
                                >
                                    {isSyncing ? "Syncing..." : "Actions"}
                                </Button>
                            }
                            onClose={() => setOpenActionMenuId(null)}
                        >
                            <ActionList
                                actionRole="menuitem"
                                items={[
                                    {
                                        content: isSynced ? "Re-sync Customer" : "Sync Customer",
                                        onAction: () => {
                                            setOpenActionMenuId(null);
                                            handleSyncCustomer(c.id);
                                        },
                                    },
                                ]}
                            />
                        </Popover>
                    </div>
                </IndexTable.Cell>
            </IndexTable.Row>
        );
    });

    return (
        <ZohoLayout
            title="Customers | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="customers"
        >
            <Page
                fullWidth
                title="Customers"
                subtitle="Manage Shopify customers and synchronize customer profiles to Zoho Books."
                primaryAction={{
                    content: loading ? "Refreshing..." : "Refresh",
                    onAction: loadData,
                    disabled: loading,
                }}
            >
                <BlockStack gap="400">
                    {/* NOTIFICATION BANNER */}
                    {notification && (
                        <Banner
                            tone={notification.type === "success" ? "success" : "critical"}
                            onDismiss={() => setNotification(null)}
                        >
                            <p>{notification.message}</p>
                        </Banner>
                    )}

                    <Card padding="0">
                        <Tabs tabs={tabs} selected={selectedTab} onSelect={setSelectedTab}>
                            <Box padding="400">
                                <TextField
                                    label="Search customers"
                                    labelHidden
                                    placeholder="Search by name, email, phone..."
                                    value={search}
                                    onChange={setSearch}
                                    autoComplete="off"
                                    clearButton
                                    onClearButtonClick={() => setSearch("")}
                                />
                            </Box>
                            <IndexTable
                                resourceName={{ singular: "customer", plural: "customers" }}
                                itemCount={filteredCustomers.length}
                                selectedItemsCount={
                                    allResourcesSelected ? "All" : selectedResources.length
                                }
                                onSelectionChange={handleSelectionChange}
                                headings={headings}
                                promotedBulkActions={promotedBulkActions}
                                loading={loading}
                            >
                                {rowMarkup}
                            </IndexTable>
                        </Tabs>
                    </Card>
                </BlockStack>

                {/* BULK RESULTS MODAL */}
                {bulkResultsModal && (
                    <Modal
                        open={!!bulkResultsModal}
                        onClose={() => {
                            setBulkResultsModal(null);
                            clearSelection();
                        }}
                        title="Bulk Customer Synchronization Results"
                        primaryAction={{
                            content: "Close & Refresh",
                            onAction: () => {
                                setBulkResultsModal(null);
                                clearSelection();
                            },
                        }}
                    >
                        <Modal.Section>
                            <BlockStack gap="300">
                                <InlineStack gap="200">
                                    <Badge>Total: {bulkResultsModal.summary?.total || 0}</Badge>
                                    <Badge tone="success">Synced: {bulkResultsModal.summary?.synced || 0}</Badge>
                                    <Badge tone="critical">Failed: {bulkResultsModal.summary?.failed || 0}</Badge>
                                    {bulkResultsModal.summary?.skipped > 0 && (
                                        <Badge tone="warning">Skipped: {bulkResultsModal.summary?.skipped}</Badge>
                                    )}
                                </InlineStack>

                                <Box borderWidth="025" borderColor="border" borderRadius="200" overflowX="auto">
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
                                                    <td style={{ padding: "10px 14px", fontWeight: 600, fontFamily: "monospace" }}>
                                                        {res.name || `ID #${res.id}`}
                                                    </td>
                                                    <td style={{ padding: "10px 14px" }}>
                                                        <Badge tone={res.status === "success" ? "success" : res.status === "skipped" ? "warning" : "critical"}>
                                                            {res.status}
                                                        </Badge>
                                                    </td>
                                                    <td style={{ padding: "10px 14px", color: "#616a75" }}>
                                                        {res.message}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </Box>
                            </BlockStack>
                        </Modal.Section>
                    </Modal>
                )}
            </Page>
        </ZohoLayout>
    );
}
