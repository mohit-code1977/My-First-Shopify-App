<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta
        name="shopify-api-key"
        content="{{ env('SHOPIFY_API_KEY') }}">

    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    <title>Zoho Integration</title>

    @vite('resources/css/zoho-sync.css')
</head>

<body>

    {{-- =========================================================
         SHOPIFY ADMIN APP NAVIGATION
         This appears under your app name in Shopify's sidebar.
    ========================================================== --}}

    <s-app-nav>

        {{-- App name itself is already Home, so no home link needed --}}

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


    {{-- =========================================================
         APPLICATION
    ========================================================== --}}

    <div class="app">

        <main class="main">

            {{-- =================================================
                 TOP BAR
            ================================================== --}}

            <header class="topbar">

                <div class="page-info">

                    <div class="page-title">
                        Zoho Integration
                    </div>

                    <div class="page-subtitle">
                        Manage Shopify product synchronization
                    </div>

                </div>


                <div class="connection">

                    <span class="connection-dot"></span>

                    <span>
                        Zoho Connected
                    </span>

                </div>

            </header>


            {{-- =================================================
                 MAIN CONTENT
            ================================================== --}}

            <section class="content">


                {{-- =================================================
                     PAGE HEADER
                ================================================== --}}

                <div class="heading-row">

                    <div class="heading">

                        <h1>
                            Product Sync
                        </h1>

                        <p>
                            Monitor and synchronize your Shopify products
                            with Zoho Books.
                        </p>

                    </div>


                    <button
                        type="button"
                        id="syncAllButton"
                        class="primary-btn">
                        <span class="button-icon">↻</span>
                        Sync All Products
                    </button>

                </div>


                {{-- =================================================
                     DYNAMIC STATISTICS
                ================================================== --}}

                @php

                $totalVariants = $variants->count();

                $syncedVariants = $variants
                ->whereNotNull('zoho_item_id')
                ->count();

                $pendingVariants =
                $totalVariants - $syncedVariants;

                $syncPercentage =
                $totalVariants > 0
                ? round(
                ($syncedVariants / $totalVariants) * 100
                )
                : 0;

                @endphp


                <div class="stats">


                    {{-- TOTAL --}}

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-label">
                                Total Variants
                            </div>

                            <div class="stat-icon">
                                ▦
                            </div>

                        </div>

                        <div
                            class="stat-value"
                            id="totalVariants">
                            {{ $totalVariants }}
                        </div>

                        <div class="stat-description">
                            Shopify variants
                        </div>

                    </div>


                    {{-- SYNCED --}}

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-label">
                                Synced
                            </div>

                            <div class="stat-icon">
                                ✓
                            </div>

                        </div>

                        <div
                            class="stat-value"
                            id="syncedVariants">
                            {{ $syncedVariants }}
                        </div>

                        <div class="stat-description">
                            Successfully connected to Zoho
                        </div>

                    </div>


                    {{-- PENDING --}}

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-label">
                                Pending
                            </div>

                            <div class="stat-icon">
                                !
                            </div>

                        </div>

                        <div
                            class="stat-value"
                            id="pendingVariants">
                            {{ $pendingVariants }}
                        </div>

                        <div class="stat-description">
                            Waiting for synchronization
                        </div>

                    </div>


                    {{-- PROGRESS --}}

                    <div class="stat-card">

                        <div class="stat-top">

                            <div class="stat-label">
                                Sync Progress
                            </div>

                            <div class="stat-icon">
                                %
                            </div>

                        </div>

                        <div
                            class="stat-value"
                            id="syncPercentage">
                            {{ $syncPercentage }}%
                        </div>

                        <div class="stat-description">
                            Overall synchronization
                        </div>

                    </div>

                </div>


                {{-- =================================================
                     PRODUCTS TABLE
                ================================================== --}}

                <div class="table-card">


                    {{-- TABLE TOOLBAR --}}

                    <div class="table-toolbar">

                        <div class="toolbar-title">
                            Shopify Products
                        </div>


                        <div class="toolbar-actions">

                            <input
                                type="text"
                                id="searchInput"
                                class="search-box"
                                placeholder="Search products..."
                                autocomplete="off">


                            <select
                                id="statusFilter"
                                class="filter-select">

                                <option value="all">
                                    All Status
                                </option>

                                <option value="synced">
                                    Synced
                                </option>

                                <option value="pending">
                                    Pending
                                </option>

                            </select>

                        </div>

                    </div>


                    {{-- =================================================
                         TABLE
                    ================================================== --}}

                    <div class="table-wrapper">

                        <table>

                            <thead>

                                <tr>

                                    <th>ID</th>

                                    <th>
                                        Product
                                    </th>

                                    <th>
                                        SKU
                                    </th>

                                    <th>
                                        Price
                                    </th>

                                    <th>
                                        Zoho Item
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody id="productTable">

                                @forelse($variants as $variant)

                                @php

                                $isSynced =
                                !is_null($variant->zoho_item_id);

                                $status =
                                $isSynced
                                ? 'synced'
                                : 'pending';

                                @endphp


                                <tr
                                    class="product-row"
                                    data-status="{{ $status }}"
                                    data-product-id="{{ $variant->id }}"
                                    data-variant-id="{{ $variant->shopify_variant_id }}">


                                    {{-- ID --}}

                                    <td>

                                        <span class="product-id">
                                            #{{ $variant->id }}
                                        </span>

                                    </td>


                                    {{-- PRODUCT --}}

                                    <td>

                                        <div class="product-name">

                                            {{ $variant->product->title ?? 'Unknown Product' }}

                                        </div>


                                        <div class="variant-name">

                                            {{ $variant->title }}

                                        </div>

                                    </td>


                                    {{-- SKU --}}

                                    <td>

                                        <span class="sku">

                                            {{ $variant->sku ?: '—' }}

                                        </span>

                                    </td>


                                    {{-- PRICE --}}

                                    <td>

                                        <span class="price">

                                            ${{ number_format(
                                                    (float) $variant->price,
                                                    2
                                                ) }}

                                        </span>

                                    </td>


                                    {{-- ZOHO ID --}}

                                    <td>

                                        @if($variant->zoho_item_id)

                                        <span class="zoho-id">

                                            {{ $variant->zoho_item_id }}

                                        </span>

                                        @else

                                        <span class="zoho-id empty-id">
                                            —
                                        </span>

                                        @endif

                                    </td>


                                    {{-- STATUS --}}

                                    <td>

                                        @if($isSynced)

                                        <span class="status synced">

                                            <span class="status-dot"></span>

                                            Synced

                                        </span>

                                        @else

                                        <span class="status pending">

                                            <span class="status-dot"></span>

                                            Pending

                                        </span>

                                        @endif

                                    </td>


                                    {{-- ACTION --}}

                                    <td>

                                        <button
                                            type="button"
                                            class="action-btn sync-button"
                                            data-variant-id="{{ $variant->id }}">
                                            {{ $isSynced ? 'Sync' : 'Sync Now' }}
                                        </button>

                                    </td>

                                </tr>


                                @empty

                                <tr>

                                    <td colspan="7">

                                        <div class="empty">

                                            <div class="empty-icon">
                                                📦
                                            </div>

                                            <strong>
                                                No products found
                                            </strong>

                                            <p>
                                                Shopify products will
                                                appear here after
                                                synchronization.
                                            </p>

                                        </div>

                                    </td>

                                </tr>

                                @endforelse

                            </tbody>

                        </table>

                    </div>


                    {{-- NO SEARCH RESULT MESSAGE --}}

                    <div
                        id="noResults"
                        class="no-results"
                        hidden>
                        No products match your search.
                    </div>

                </div>

            </section>

        </main>

    </div>


    {{-- =========================================================
         JAVASCRIPT
    ========================================================== --}}

    @vite('resources/js/zoho-sync.js')

</body>

</html>