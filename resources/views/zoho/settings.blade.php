<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="shopify-api-key" content="{{ env('SHOPIFY_API_KEY') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    <title>Settings</title>
    @vite('resources/css/zoho-sync.css')
</head>

<body>
    <s-app-nav>
        <s-link href="{{ route('zoho.sync', request()->query()) }}">
            Products
        </s-link>

        <s-link href="{{ route('zoho.sync.history', request()->query()) }}">
            Sync History
        </s-link>

        <s-link href="{{ route('zoho.settings', request()->query()) }}">
            Settings
        </s-link>
    </s-app-nav>

    <div class="app">
        <main class="main">
            <header class="topbar">
                <div>
                    <div class="page-title">Zoho Integration</div>
                    <div class="page-subtitle">Manage Shopify product synchronization</div>
                </div>

                <div class="connection">
                    <span class="connection-dot"></span>
                    {{ $zohoConnection ? 'Zoho Connected' : 'Zoho Disconnected' }}
                </div>
            </header>

            <section class="content">
                <div class="heading-row">
                    <div class="heading">
                        <h1>Settings</h1>
                        <p>Manage your Zoho Books integration and synchronization settings.</p>
                    </div>
                </div>

                <div class="settings-grid">
                    <div class="table-card">
                        <div class="table-toolbar">
                            <div>
                                <div class="toolbar-title">Zoho Books Connection</div>
                                <p class="settings-description">
                                    Connection details for your Zoho Books organization.
                                </p>
                            </div>
                        </div>

                        <div class="settings-body">
                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Connection Status</div>
                                    <div class="setting-value">
                                        @if($zohoConnection)
                                        <span class="status synced">
                                            <span class="status-dot"></span>
                                            Connected
                                        </span>
                                        @else
                                        <span class="status pending">
                                            <span class="status-dot"></span>
                                            Disconnected
                                        </span>
                                        @endif
                                    </div>
                                </div>

                                @if(!$zohoConnection)
                                <!-- <s-link href="/zoho/connect">
                                    <button type="button" class="sync-button">
                                        Connect Zoho Books
                                    </button>
                                </s-link> -->
                                <button type="button" class="sync-button" id="connectZohoButton">
                                    Connect Zoho Books
                                </button>
                                @endif
                            </div>

                            @if($zohoConnection)
                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Organization ID</div>
                                    <div class="setting-value zoho-id">
                                        {{ $zohoConnection->organization_id }}
                                    </div>
                                </div>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Access Token</div>
                                    <div class="setting-value">
                                        <span class="masked-value">••••••••••••••••</span>
                                    </div>
                                </div>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Token Expires</div>
                                    <div class="setting-value">
                                        {{ $zohoConnection->expires_at?->format('d M Y, h:i A') ?? 'Unknown' }}
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>

                    <div class="table-card">
                        <div class="table-toolbar">
                            <div>
                                <div class="toolbar-title">Synchronization</div>
                                <p class="settings-description">
                                    Control how Shopify products are synchronized with Zoho Books.
                                </p>
                            </div>
                        </div>

                        <div class="settings-body">
                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Product Synchronization</div>
                                    <div class="setting-description">
                                        Products are synchronized manually using the Sync buttons.
                                    </div>
                                </div>
                                <span class="status synced">
                                    <span class="status-dot"></span>
                                    Active
                                </span>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Duplicate Protection</div>
                                    <div class="setting-description">
                                        Existing Zoho items are updated instead of creating duplicates.
                                    </div>
                                </div>
                                <span class="status synced">
                                    <span class="status-dot"></span>
                                    Enabled
                                </span>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Change Detection</div>
                                    <div class="setting-description">
                                        Unchanged products are skipped using synchronization hashes.
                                    </div>
                                </div>
                                <span class="status synced">
                                    <span class="status-dot"></span>
                                    Enabled
                                </span>
                            </div>
                        </div>
                    </div>

                    @if($zohoConnection)
                    <div class="table-card danger-card">
                        <div class="table-toolbar">
                            <div>
                                <div class="toolbar-title">Danger Zone</div>
                                <p class="settings-description">
                                    Disconnecting will remove the stored Zoho connection from this app.
                                </p>
                            </div>
                        </div>

                        <div class="settings-body">
                            <div class="setting-row">
                                <div>
                                    <div class="setting-label">Disconnect Zoho Books</div>
                                    <div class="setting-description">
                                        You will need to authorize Zoho Books again before synchronization can continue.
                                    </div>
                                </div>

                                <button type="button" class="danger-btn" id="disconnectButton">
                                    Disconnect
                                </button>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </section>
        </main>
    </div>

    <script>
        const disconnectButton = document.getElementById('disconnectButton');

        if (disconnectButton) {
            disconnectButton.addEventListener('click', async () => {
                if (!confirm('Are you sure you want to disconnect Zoho Books?')) {
                    return;
                }

                disconnectButton.disabled = true;
                disconnectButton.innerText = 'Disconnecting...';

                try {
                    const response = await fetch('/zoho/settings/disconnect', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        }
                    });

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Failed to disconnect Zoho.');
                    }

                    window.location.reload();
                } catch (error) {
                    console.error('Zoho disconnect failed:', error);
                    alert(error.message);

                    disconnectButton.disabled = false;
                    disconnectButton.innerText = 'Disconnect';
                }
            });
        }


        const connectZohoButton = document.getElementById('connectZohoButton');

        if (connectZohoButton) {
            connectZohoButton.addEventListener('click', () => {
                const params = new URLSearchParams(window.location.search);
                const shop = params.get('shop');
                const host = params.get('host');

                if (!shop || !host) {
                    alert('Shopify context is missing. Please reopen the app from Shopify Admin.');
                    return;
                }

                window.top.location.href = `/zoho/connect?shop=${encodeURIComponent(shop)}&host=${encodeURIComponent(host)}`;
            });
        }
    </script>
</body>

</html>