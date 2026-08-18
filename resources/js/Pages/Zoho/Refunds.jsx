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
    Spinner,
    useIndexResourceState,
} from "@shopify/polaris";
import ZohoLayout from "@/Layouts/ZohoLayout";

const REFUNDS_DATA_URL = "/api/zoho/refunds";
const SYNC_REFUND_URL = "/zoho/sync-refund";
const BULK_SYNC_REFUNDS_URL = "/zoho/bulk-sync-refunds";

export default function Refunds({
    shop,
    refunds = [],
    zohoConnected = false,
    host = "",
}) {
    const [initialLoading, setInitialLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [shopData, setShopData] = useState(shop || {});
    const [connectedState, setConnectedState] = useState(zohoConnected);
    const [refundList, setRefundList] = useState(refunds || []);
    const [search, setSearch] = useState("");
    const [selectedTab, setSelectedTab] = useState(0);
    const [syncingRefundId, setSyncingRefundId] = useState(null);
    const [notification, setNotification] = useState(null);
    const [selectedRefund, setSelectedRefund] = useState(null);
    const [openActionMenuId, setOpenActionMenuId] = useState(null);

    // Bulk Selection State
    const [bulkSyncing, setBulkSyncing] = useState(false);
    const [bulkResultsModal, setBulkResultsModal] = useState(null);

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

    const loadData = async (isRefresh = false) => {
        if (isRefresh) {
            setRefreshing(true);
        } else {
            setInitialLoading(true);
        }
        try {
            const token = await window.shopify?.idToken();
            const headers = {
                Accept: "application/json",
                "X-CSRF-TOKEN": getCsrfToken(),
                Authorization: token ? `Bearer ${token}` : "",
            };
            const response = await fetch(REFUNDS_DATA_URL, { headers });
            const data = await response.json();

            if (response.ok && data.success) {
                setRefundList(data.refunds || []);
                if (data.shop) setShopData(data.shop);
                if (typeof data.zohoConnected === "boolean") {
                    setConnectedState(data.zohoConnected);
                }
            }
        } catch (error) {
            console.error("Failed to load refunds:", error);
        } finally {
            setInitialLoading(false);
            setRefreshing(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    // Keep selectedRefund updated if refundList changes
    useEffect(() => {
        if (selectedRefund) {
            const updated = refundList.find((r) => r.id === selectedRefund.id);
            if (updated) {
                setSelectedRefund(updated);
            }
        }
    }, [refundList]);

    const handleRetrySync = async (refundId) => {
        if (!connectedState) {
            setNotification({
                type: "error",
                message: "Zoho is not connected. Please connect in Settings first.",
            });
            return;
        }

        setSyncingRefundId(refundId);
        setNotification(null);

        try {
            const token = await window.shopify?.idToken();
            const response = await fetch(SYNC_REFUND_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({ refund_id: refundId }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setNotification({
                    type: "success",
                    message: data.message || "Credit Note synchronized to Zoho successfully.",
                });
                await loadData(true);
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Credit Note sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during refund sync retry.",
            });
        } finally {
            setSyncingRefundId(null);
        }
    };

    const tabKeys = ["all", "pending", "synced", "failed"];
    const currentTabKey = tabKeys[selectedTab] || "all";

    const filteredRefunds = refundList.filter((r) => {
        const orderNum = r.order?.order_number || r.shopify_order_id || "";
        const customerName = r.order?.customer
            ? `${r.order.customer.first_name} ${r.order.customer.last_name} ${r.order.customer.email}`
            : "Guest Customer";
        const refundIdStr = String(r.shopify_refund_id || r.id);
        const creditNoteStr = String(r.creditnote_number || r.zoho_creditnote_id || "");

        const matchesSearch =
            refundIdStr.toLowerCase().includes(search.toLowerCase()) ||
            orderNum.toLowerCase().includes(search.toLowerCase()) ||
            customerName.toLowerCase().includes(search.toLowerCase()) ||
            creditNoteStr.toLowerCase().includes(search.toLowerCase());

        if (!matchesSearch) return false;

        const status = (r.sync_status || "pending").toLowerCase();

        if (currentTabKey === "pending") return status === "pending";
        if (currentTabKey === "synced") return status === "synced";
        if (currentTabKey === "failed") return status === "failed";
        return true;
    });

    const {
        selectedResources,
        allResourcesSelected,
        handleSelectionChange,
        clearSelection,
    } = useIndexResourceState(filteredRefunds);

    const handleBulkSync = async (onlyFailed = false) => {
        let idsToSync = selectedResources;
        if (onlyFailed) {
            const failedSet = new Set(
                refundList
                    .filter((r) => (r.sync_status || "").toLowerCase() === "failed")
                    .map((r) => r.id)
            );
            idsToSync = selectedResources.filter((id) => failedSet.has(id));
            if (idsToSync.length === 0) {
                idsToSync = selectedResources;
            }
        }

        if (idsToSync.length === 0) return;

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
            const response = await fetch(BULK_SYNC_REFUNDS_URL, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    Authorization: token ? `Bearer ${token}` : "",
                },
                body: JSON.stringify({
                    refund_ids: idsToSync,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setBulkResultsModal({
                    summary: data.summary || {},
                    results: data.results || [],
                });
                await loadData(true);
            } else {
                setNotification({
                    type: "error",
                    message: data.message || "Bulk Credit Note sync failed.",
                });
            }
        } catch (error) {
            setNotification({
                type: "error",
                message: "Network error during bulk Credit Note sync.",
            });
        } finally {
            setBulkSyncing(false);
        }
    };

    const tabs = [
        { id: "all", content: `All (${refundList.length})` },
        {
            id: "pending",
            content: `Pending (${refundList.filter((r) => (r.sync_status || "").toLowerCase() === "pending").length})`,
        },
        {
            id: "synced",
            content: `Synced (${refundList.filter((r) => (r.sync_status || "").toLowerCase() === "synced").length})`,
        },
        {
            id: "failed",
            content: `Failed (${refundList.filter((r) => (r.sync_status || "").toLowerCase() === "failed").length})`,
        },
    ];

    const promotedBulkActions = [
        {
            content: "Sync Credit Notes",
            onAction: () => handleBulkSync(false),
            disabled: bulkSyncing,
        },
        {
            content: "Retry Failed Only",
            onAction: () => handleBulkSync(true),
            disabled: bulkSyncing,
        },
    ];

    const headings = [
        { title: "Refund ID" },
        { title: "Order #" },
        { title: "Customer" },
        { title: "Refund Date" },
        { title: "Amount" },
        { title: "Restock" },
        { title: "Zoho Credit Note" },
        { title: "Sync Status" },
        { title: "Actions", alignment: "end" },
    ];

    const rowMarkup = filteredRefunds.map((r, index) => {
        const isSyncing = syncingRefundId === r.id;
        const isSelected = selectedResources.includes(r.id);
        const status = (r.sync_status || "pending").toLowerCase();
        const isSynced = status === "synced" || !!r.zoho_creditnote_id;
        const isFailed = status === "failed";
        const isMenuOpen = openActionMenuId === r.id;

        return (
            <IndexTable.Row
                id={r.id}
                key={r.id}
                selected={isSelected}
                position={index}
            >
                <IndexTable.Cell>
                    <Text variant="bodyMd" fontWeight="bold" as="span">
                        #{r.shopify_refund_id || r.id}
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Text variant="bodyMd" fontWeight="semibold" as="span">
                        <span style={{ color: "#005bd3" }}>
                            {r.order?.name || (r.order?.order_number ? `#${r.order.order_number}` : `#${r.shopify_order_id}`)}
                        </span>
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {r.order?.customer ? (
                        <BlockStack gap="050">
                            <Text variant="bodyMd" fontWeight="semibold" as="span">
                                {r.order.customer.first_name} {r.order.customer.last_name}
                            </Text>
                            <Text variant="bodySm" tone="subdued" as="span">
                                {r.order.customer.email}
                            </Text>
                        </BlockStack>
                    ) : (
                        <Text variant="bodyMd" tone="subdued" as="span">
                            Guest Customer
                        </Text>
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Text variant="bodySm" tone="subdued" as="span">
                        {r.created_at ? new Date(r.created_at).toLocaleDateString() : "—"}
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Text variant="bodyMd" fontWeight="bold" as="span">
                        ${parseFloat(r.amount || 0).toFixed(2)}{" "}
                        <Text variant="bodySm" tone="subdued" as="span">
                            {r.currency || "USD"}
                        </Text>
                    </Text>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Badge tone={r.restocked ? "success" : "subdued"}>
                        {r.restocked ? "Restocked" : "No Restock"}
                    </Badge>
                </IndexTable.Cell>
                <IndexTable.Cell>
                    {r.creditnote_number || r.zoho_creditnote_id ? (
                        <Text variant="bodySm" tone="subdued" as="span">
                            <code style={{ fontSize: "11px", backgroundColor: "#f1f2f4", padding: "2px 6px", borderRadius: "4px", color: "#616a75" }}>
                                {r.creditnote_number || `CN-${r.zoho_creditnote_id}`}
                            </code>
                        </Text>
                    ) : (
                        <Text variant="bodySm" tone="subdued" as="span">—</Text>
                    )}
                </IndexTable.Cell>
                <IndexTable.Cell>
                    <Badge tone={isSynced ? "success" : isFailed ? "critical" : "warning"}>
                        {r.sync_status ? r.sync_status.toUpperCase() : "PENDING"}
                    </Badge>
                </IndexTable.Cell>
                <IndexTable.Cell alignment="end">
                    <div onClick={(e) => e.stopPropagation()}>
                        <Popover
                            active={isMenuOpen}
                            activator={
                                <Button
                                    size="slim"
                                    onClick={() => setOpenActionMenuId(isMenuOpen ? null : r.id)}
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
                                        content: "View Details",
                                        onAction: () => {
                                            setOpenActionMenuId(null);
                                            setSelectedRefund(r);
                                        },
                                    },
                                    {
                                        content: isFailed ? "Retry Sync" : "Sync Credit Note",
                                        onAction: () => {
                                            setOpenActionMenuId(null);
                                            handleRetrySync(r.id);
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
            title="Refunds & Credit Notes | Zoho Books Integration"
            shop={shopData}
            zohoConnected={connectedState}
            host={host}
            activePage="refunds"
        >
            <Page
                fullWidth
                title="Refunds & Credit Notes"
                subtitle="View Shopify refunds and sync Credit Notes to Zoho Books."
                primaryAction={{
                    content: "Refresh",
                    onAction: () => loadData(true),
                    loading: refreshing,
                    disabled: refreshing || bulkSyncing,
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
                                    label="Search refunds"
                                    labelHidden
                                    placeholder="Search by refund ID, order #, customer, credit note..."
                                    value={search}
                                    onChange={setSearch}
                                    autoComplete="off"
                                    clearButton
                                    onClearButtonClick={() => setSearch("")}
                                />
                            </Box>
                            {initialLoading ? (
                                <Box padding="800">
                                    <BlockStack align="center" inlineAlign="center" gap="300">
                                        <Spinner accessibilityLabel="Loading refunds" size="large" />
                                        <Text tone="subdued" variant="bodyMd" as="p">
                                            Loading refunds and credit notes...
                                        </Text>
                                    </BlockStack>
                                </Box>
                            ) : (
                                <IndexTable
                                    resourceName={{ singular: "refund", plural: "refunds" }}
                                    itemCount={filteredRefunds.length}
                                    selectedItemsCount={
                                        allResourcesSelected ? "All" : selectedResources.length
                                    }
                                    onSelectionChange={handleSelectionChange}
                                    headings={headings}
                                    promotedBulkActions={promotedBulkActions}
                                    loading={false}
                                >
                                    {rowMarkup}
                                </IndexTable>
                            )}
                        </Tabs>
                    </Card>
                </BlockStack>

                {/* REFUND DETAILS MODAL */}
                {selectedRefund && (
                    <Modal
                        open={!!selectedRefund}
                        onClose={() => setSelectedRefund(null)}
                        title={`Refund Details — #${selectedRefund.shopify_refund_id || selectedRefund.id}`}
                        primaryAction={{
                            content: "Close",
                            onAction: () => setSelectedRefund(null),
                        }}
                    >
                        <Modal.Section>
                            <BlockStack gap="400">
                                <InlineStack gap="400">
                                    <Box padding="300" background="bg-surface-secondary" borderRadius="200">
                                        <Text tone="subdued" variant="bodySm" as="p">Refund Amount</Text>
                                        <Text variant="headingSm" as="p">
                                            ${parseFloat(selectedRefund.amount || 0).toFixed(2)} {selectedRefund.currency || "USD"}
                                        </Text>
                                    </Box>
                                    <Box padding="300" background="bg-surface-secondary" borderRadius="200">
                                        <Text tone="subdued" variant="bodySm" as="p">Restock Status</Text>
                                        <Badge tone={selectedRefund.restocked ? "success" : "subdued"}>
                                            {selectedRefund.restocked ? "Inventory Restocked" : "No Restock"}
                                        </Badge>
                                    </Box>
                                </InlineStack>

                                <Card>
                                    <BlockStack gap="200">
                                        <Text variant="headingSm" as="h3">Synchronization Info</Text>
                                        <Text variant="bodySm" as="p">
                                            <strong>Sync Status: </strong>
                                            <Badge tone={selectedRefund.sync_status === "synced" ? "success" : selectedRefund.sync_status === "failed" ? "critical" : "warning"}>
                                                {selectedRefund.sync_status ? selectedRefund.sync_status.toUpperCase() : "PENDING"}
                                            </Badge>
                                        </Text>
                                        <Text variant="bodySm" as="p">
                                            <strong>Zoho Credit Note ID: </strong>
                                            <code>{selectedRefund.zoho_creditnote_id || "Not Created"}</code>
                                        </Text>
                                        <Text variant="bodySm" as="p">
                                            <strong>Credit Note #: </strong>
                                            <code>{selectedRefund.creditnote_number || "—"}</code>
                                        </Text>

                                        {selectedRefund.sync_status === "failed" && selectedRefund.error_message && (
                                            <Banner tone="critical">
                                                <p><strong>Sync Error: </strong>{selectedRefund.error_message}</p>
                                            </Banner>
                                        )}

                                        <InlineStack align="end">
                                            <Button
                                                variant="primary"
                                                size="slim"
                                                onClick={() => handleRetrySync(selectedRefund.id)}
                                                loading={syncingRefundId === selectedRefund.id}
                                            >
                                                {selectedRefund.sync_status === "failed" ? "Retry Sync to Zoho" : "Sync Credit Note"}
                                            </Button>
                                        </InlineStack>
                                    </BlockStack>
                                </Card>
                            </BlockStack>
                        </Modal.Section>
                    </Modal>
                )}

                {/* BULK RESULTS MODAL */}
                {bulkResultsModal && (
                    <Modal
                        open={!!bulkResultsModal}
                        onClose={() => {
                            setBulkResultsModal(null);
                            clearSelection();
                        }}
                        title="Bulk Credit Note Synchronization Results"
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
                                                <th style={{ padding: "10px 14px" }}>Refund ID</th>
                                                <th style={{ padding: "10px 14px" }}>Status</th>
                                                <th style={{ padding: "10px 14px" }}>Message</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {bulkResultsModal.results?.map((res, idx) => (
                                                <tr key={idx} style={{ borderBottom: "1px solid #f1f2f4" }}>
                                                    <td style={{ padding: "10px 14px", fontWeight: 600, fontFamily: "monospace" }}>
                                                        ID #{res.id}
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
