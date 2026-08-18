<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_products,write_products,read_orders,write_orders,read_customers,write_customers,read_inventory,write_inventory,read_locations'),
    ],

    'zoho' => [
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'redirect_uri' => env('ZOHO_REDIRECT_URI'),
        'webhook_secret' => env('ZOHO_WEBHOOK_SECRET'),
        'oauth_initiation_url' => env('ZOHO_OAUTH_INITIATION_URL', 'https://accounts.zoho.com'),
        'accounts_url' => env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.in'),
        'api_url' => env('ZOHO_API_URL', 'https://www.zohoapis.in'),
        'scopes' => [
            'ZohoBooks.settings.READ',
            'ZohoBooks.settings.CREATE',
            'ZohoBooks.settings.UPDATE',
            'ZohoBooks.contacts.READ',
            'ZohoBooks.contacts.CREATE',
            'ZohoBooks.contacts.UPDATE',
            'ZohoBooks.salesorders.READ',
            'ZohoBooks.salesorders.CREATE',
            'ZohoBooks.salesorders.UPDATE',
            'ZohoBooks.invoices.READ',
            'ZohoBooks.invoices.CREATE',
            'ZohoBooks.invoices.UPDATE',
            'ZohoBooks.customerpayments.READ',
            'ZohoBooks.customerpayments.CREATE',
            'ZohoBooks.creditnotes.READ',
            'ZohoBooks.creditnotes.CREATE',
            'ZohoBooks.creditnotes.UPDATE',
            'ZohoBooks.creditnotes.DELETE',
            'ZohoInventory.settings.READ',
            'ZohoInventory.items.READ',
            'ZohoInventory.items.CREATE',
            'ZohoInventory.items.UPDATE',
            'ZohoInventory.inventoryadjustments.READ',
            'ZohoInventory.inventoryadjustments.CREATE',
            'ZohoInventory.inventoryadjustments.UPDATE',
            'ERP.settings.READ',
            'ERP.inventoryadjustments.READ',
            'ERP.inventoryadjustments.CREATE',
            'ERP.inventoryadjustments.UPDATE',
        ],
        'payment_gateways' => [
            'shopify_payments' => ['payment_mode' => 'creditcard', 'account_id' => null],
            'stripe' => ['payment_mode' => 'creditcard', 'account_id' => null],
            'paypal' => ['payment_mode' => 'paypal', 'account_id' => null],
            'cash_on_delivery' => ['payment_mode' => 'cash', 'account_id' => null],
            'cod' => ['payment_mode' => 'cash', 'account_id' => null],
            'manual' => ['payment_mode' => 'others', 'account_id' => null],
            'bank_transfer' => ['payment_mode' => 'banktransfer', 'account_id' => null],
        ],
    ],

];
