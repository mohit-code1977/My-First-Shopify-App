import React from "react";
import { router } from "@inertiajs/react";

export default function Settings({ shop, zohoConnection }) {
    const disconnect = () => {
        if (!confirm("Are you sure you want to disconnect Zoho Books?")) {
            return;
        }

        router.post("/zoho/settings/disconnect");
    };

    return (
        <div style={{ padding: "30px" }}>
            <h1>Settings</h1>

            <p>Shopify Store: {shop?.shop_domain}</p>

            <hr />

            <h2>Zoho Books Connection</h2>

            {zohoConnection ? (
                <>
                    <p>
                        <strong>Status:</strong> Connected
                    </p>

                    <p>
                        <strong>Organization ID:</strong>{" "}
                        {zohoConnection.organization_id}
                    </p>

                    <p>
                        <strong>Token Expires:</strong>{" "}
                        {zohoConnection.expires_at}
                    </p>

                    <button onClick={disconnect}>Disconnect Zoho</button>
                </>
            ) : (
                <>
                    <p>Zoho Books is not connected.</p>

                    <a
                        href={`/zoho/connect?shop=${encodeURIComponent(
                            shop?.shop_domain,
                        )}`}
                    >
                        Connect Zoho Books
                    </a>
                </>
            )}
        </div>
    );
}
