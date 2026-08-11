import React, { useState } from "react";
import { Head } from "@inertiajs/react";

export default function Sync({ shop, variants }) {
    const [search, setSearch] = useState("");
    const [status, setStatus] = useState("all");

    const filteredVariants = variants.filter((variant) => {
        const searchText = search.toLowerCase();

        const matchesSearch =
            variant.title?.toLowerCase().includes(searchText) ||
            variant.sku?.toLowerCase().includes(searchText) ||
            variant.product?.title?.toLowerCase().includes(searchText);

        return matchesSearch;
    });

    return (
        <>
            <Head title="Zoho Sync" />

            <div style={{ padding: "30px" }}>
                <h1>Zoho Books Integration</h1>

                <p>Shopify Store: {shop.shop_domain}</p>

                <hr />

                <input
                    type="text"
                    placeholder="Search products..."
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />

                <select
                    value={status}
                    onChange={(e) => setStatus(e.target.value)}
                >
                    <option value="all">All</option>
                    <option value="synced">Synced</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>

                <button>Sync All</button>

                <div style={{ marginTop: "30px" }}>
                    {filteredVariants.map((variant) => (
                        <div
                            key={variant.id}
                            style={{
                                padding: "15px",
                                borderBottom: "1px solid #ddd",
                            }}
                        >
                            <strong>{variant.product?.title}</strong>

                            <div>Variant: {variant.title}</div>

                            <div>SKU: {variant.sku || "N/A"}</div>

                            <div>Price: ₹{variant.price ?? "0.00"}</div>

                            <div>
                                Inventory: {variant.inventory_quantity ?? 0}
                            </div>

                            <button>Sync</button>
                        </div>
                    ))}
                </div>

                {filteredVariants.length === 0 && <p>No products found.</p>}
            </div>
        </>
    );
}
