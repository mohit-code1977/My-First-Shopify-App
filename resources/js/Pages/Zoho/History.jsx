import React from "react";

export default function History({ shop, histories }) {
    return (
        <div style={{ padding: "30px" }}>
            <h1>Sync History</h1>

            <p>Shopify Store: {shop?.shop_domain}</p>

            <hr />

            {histories?.data?.length === 0 ? (
                <p>No synchronization history found.</p>
            ) : (
                histories?.data?.map((history) => (
                    <div
                        key={history.id}
                        style={{
                            padding: "15px 0",
                            borderBottom: "1px solid #ddd",
                        }}
                    >
                        <strong>
                            {history.product_variant?.product?.title ??
                                "Unknown Product"}
                        </strong>

                        <div>Action: {history.action}</div>

                        <div>Status: {history.status}</div>

                        <div>Message: {history.message}</div>

                        <div>{history.created_at}</div>
                    </div>
                ))
            )}
        </div>
    );
}
