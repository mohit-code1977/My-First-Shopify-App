import "../css/app.css";
import "../css/zoho-sync.css";
import "./bootstrap";

import { createInertiaApp } from "@inertiajs/react";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createRoot } from "react-dom/client";

const appName = import.meta.env.VITE_APP_NAME || "Zoho Books Integration";

function ShopifyAppNavigation() {
    return (
        <s-app-nav>
            <s-link href="/zoho/sync">Products</s-link>

            <s-link href="/zoho/sync/history">Sync History</s-link>

            <s-link href="/zoho/settings">Settings</s-link>
        </s-app-nav>
    );
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob("./Pages/**/*.jsx"),
        ),

    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <>
                <ShopifyAppNavigation />

                <App {...props} />
            </>,
        );
    },

    progress: {
        color: "#111827",
    },
});
