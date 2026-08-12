<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}">

    <meta
        name="shopify-api-key"
        content="{{ env('SHOPIFY_API_KEY') }}">

    <title inertia>
        {{ config('app.name', 'Zoho Books Integration') }}
    </title>

    {{-- Shopify App Bridge --}}
    <script
        src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

    @viteReactRefresh

    @vite([
    'resources/css/app.css',
    'resources/css/zoho-sync.css',
    'resources/js/app.jsx',
    ])

    @inertiaHead
</head>

<body>

    @inertia

</body>

</html>