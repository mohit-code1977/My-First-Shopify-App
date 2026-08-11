<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Shopify App Bridge --}}
    <meta
        name="shopify-api-key"
        content="{{ env('SHOPIFY_API_KEY') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>Sync History</title>
    @vite('resources/css/zoho-sync.css')
</head>

<body>

    <body>
        {{-- =====================================================
         SHOPIFY ADMIN APP NAVIGATION
         Appears under the app name in Shopify Admin
    ====================================================== --}}
        <s-app-nav>
            <s-link href="/zoho/sync">
                Products
            </s-link>

            <s-link href="/zoho/sync/history">
                Sync History
            </s-link>

            <s-link href="/zoho/settings">
                Settings
            </s-link>
        </s-app-nav>

        <div class="app">
            {{-- =====================================================
         MAIN
    ====================================================== --}}
            <main class="main">
                {{-- TOP BAR --}}
                <header class="topbar">
                    <div>
                        <div class="page-title">
                            Zoho Integration
                        </div>
                        <div class="page-subtitle">
                            Manage Shopify product synchronization
                        </div>
                    </div>
                    <div class="connection">
                        <span class="connection-dot"></span>
                        Zoho Connected
                    </div>
                </header>
                {{-- =================================================
             CONTENT
        ================================================== --}}
                <section class="content">
                    {{-- PAGE HEADER --}}
                    <div class="heading-row">
                        <div class="heading">
                            <h1>
                                Sync History
                            </h1>
                            <p>
                                View the synchronization activity between Shopify and Zoho Books.
                            </p>
                        </div>
                    </div>
                    {{-- =================================================
                 HISTORY TABLE
            ================================================== --}}
                    <div class="table-card">
                        <div class="table-toolbar">
                            <div class="toolbar-title">
                                Synchronization Activity
                            </div>
                        </div>
                        <div class="table-wrapper">
                            @if($histories->count())
                            <table>
                                <thead>
                                    <tr>
                                        <th>
                                            ID
                                        </th>
                                        <th>
                                            PRODUCT
                                        </th>
                                        <th>
                                            SKU
                                        </th>
                                        <th>
                                            ACTION
                                        </th>
                                        <th>
                                            STATUS
                                        </th>
                                        <th>
                                            ZOHO ITEM
                                        </th>
                                        <th>
                                            MESSAGE
                                        </th>
                                        <th>
                                            DATE
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($histories as $history)
                                    <tr>
                                        {{-- ID --}}
                                        <td>
                                            #{{ $history->id }}
                                        </td>
                                        {{-- PRODUCT --}}
                                        <td>
                                            <div class="product-name">
                                                {{ $history->productVariant->product->title ?? 'Unknown Product' }}
                                            </div>
                                            <div class="variant-name">
                                                {{ $history->productVariant->title ?? 'Unknown Variant' }}
                                            </div>
                                        </td>
                                        {{-- SKU --}}
                                        <td>
                                            <span class="sku">
                                                {{ $history->productVariant->sku ?? '—' }}
                                            </span>
                                        </td>
                                        {{-- ACTION --}}
                                        <td>
                                            <span class="history-action {{ strtolower($history->action) }}">
                                                {{ ucfirst($history->action) }}
                                            </span>
                                        </td>
                                        {{-- STATUS --}}
                                        <td>
                                            @if($history->status === 'success')
                                            <span class="status synced">
                                                <span class="status-dot"></span>
                                                Success
                                            </span>
                                            @else
                                            <span class="status pending">
                                                <span class="status-dot"></span>
                                                {{ ucfirst($history->status) }}
                                            </span>
                                            @endif
                                        </td>
                                        {{-- ZOHO ITEM --}}
                                        <td>
                                            <span class="zoho-id">
                                                {{ $history->zoho_item_id ?? '—' }}
                                            </span>
                                        </td>
                                        {{-- MESSAGE --}}
                                        <td>
                                            <span class="history-message">
                                                {{ $history->message ?? '—' }}
                                            </span>
                                        </td>
                                        {{-- DATE --}}
                                        <td>
                                            <div class="history-date">
                                                {{ $history->synced_at?->format('d M Y') }}
                                            </div>
                                            <div class="history-time">
                                                {{ $history->synced_at?->format('h:i A') }}
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @else
                            <div class="empty">
                                <h3>
                                    No synchronization history
                                </h3>
                                <p>
                                    Sync a Shopify product with Zoho Books to see activity here.
                                </p>
                            </div>
                            @endif
                        </div>
                        {{-- =================================================
                     PAGINATION
                ================================================== --}}
                        @if($histories->hasPages())
                        <div class="pagination">
                            @if($histories->onFirstPage())
                            <span class="pagination-button disabled">
                                Previous
                            </span>
                            @else
                            <s-link href="{{ $histories->previousPageUrl() }}">
                                <span class="pagination-button">
                                    Previous
                                </span>
                            </s-link>
                            @endif
                            <span class="pagination-info">
                                Page {{ $histories->currentPage() }}
                                of {{ $histories->lastPage() }}
                            </span>
                            @if($histories->hasMorePages())
                            <s-link href="{{ $histories->nextPageUrl() }}">
                                <span class="pagination-button">
                                    Next
                                </span>
                            </s-link>
                            @else
                            <span class="pagination-button disabled">
                                Next
                            </span>
                            @endif
                        </div>
                        @endif
                    </div>
                </section>
            </main>
        </div>
    </body>

</html>