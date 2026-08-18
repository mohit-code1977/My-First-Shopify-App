<?php

namespace App\Services;

class ZohoDatacenter
{
    /**
     * Global OAuth initiation entry point for server-based applications.
     */
    public const GLOBAL_ACCOUNTS_URL = 'https://accounts.zoho.com';

    /**
     * Map of allowed Accounts domain hosts to their corresponding API domain hosts.
     */
    public const ALLOWED_DATACENTERS = [
        'accounts.zoho.com' => 'www.zohoapis.com',
        'accounts.zoho.eu' => 'www.zohoapis.eu',
        'accounts.zoho.in' => 'www.zohoapis.in',
        'accounts.zoho.com.au' => 'www.zohoapis.com.au',
        'accounts.zoho.jp' => 'www.zohoapis.jp',
        'accounts.zoho.ca' => 'www.zohoapis.ca',
        'accounts.zoho.com.cn' => 'www.zohoapis.com.cn',
        'accounts.zoho.sa' => 'www.zohoapis.sa',
    ];

    /**
     * Map of location query codes to Accounts domain hosts.
     */
    public const LOCATION_MAP = [
        'us' => 'accounts.zoho.com',
        'eu' => 'accounts.zoho.eu',
        'in' => 'accounts.zoho.in',
        'au' => 'accounts.zoho.com.au',
        'jp' => 'accounts.zoho.jp',
        'ca' => 'accounts.zoho.ca',
        'cn' => 'accounts.zoho.com.cn',
        'sa' => 'accounts.zoho.sa',
    ];

    /**
     * Get global initiation accounts URL.
     */
    public static function getGlobalAccountsUrl(): string
    {
        return self::GLOBAL_ACCOUNTS_URL;
    }

    /**
     * Resolve initiation Accounts URL for a new OAuth authorization flow.
     * Always uses ZOHO_OAUTH_INITIATION_URL (https://accounts.zoho.com).
     */
    public static function getInitiationAccountsUrl(?\App\Models\Shop $shop = null): string
    {
        $configured = config('services.zoho.oauth_initiation_url') ?: env('ZOHO_OAUTH_INITIATION_URL');
        $validated = self::validateAccountsUrl($configured);

        if ($validated) {
            return $validated;
        }

        return self::GLOBAL_ACCOUNTS_URL;
    }

    /**
     * Validate and sanitize an Accounts URL. Must be HTTPS and have an allowed Accounts host.
     */
    public static function validateAccountsUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (!isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);

        if (!array_key_exists($host, self::ALLOWED_DATACENTERS)) {
            return null;
        }

        return "https://{$host}";
    }

    /**
     * Validate and sanitize an API URL. Must be HTTPS and have an allowed API host.
     */
    public static function validateApiUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (!isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);

        $allowedApiHosts = array_values(self::ALLOWED_DATACENTERS);

        if (!in_array($host, $allowedApiHosts, true)) {
            return null;
        }

        return "https://{$host}";
    }

    /**
     * Resolve API URL for a given ZohoConnection model cleanly.
     */
    public static function resolveApiUrlForConnection(?\App\Models\ZohoConnection $connection): ?string
    {
        if ($connection) {
            $apiUrl = self::validateApiUrl($connection->api_url);
            if ($apiUrl) {
                return $apiUrl;
            }

            if (!empty($connection->api_domain)) {
                $apiUrl = self::validateApiUrl("https://{$connection->api_domain}");
                if ($apiUrl) {
                    return $apiUrl;
                }
            }

            return null;
        }

        return self::validateApiUrl(config('services.zoho.api_url') ?: env('ZOHO_API_URL'));
    }

    /**
     * Resolve Accounts URL for a given ZohoConnection model cleanly.
     */
    public static function resolveAccountsUrlForConnection(?\App\Models\ZohoConnection $connection): ?string
    {
        if ($connection) {
            $accountsUrl = self::validateAccountsUrl($connection->accounts_url);
            if ($accountsUrl) {
                return $accountsUrl;
            }

            return null;
        }

        return self::validateAccountsUrl(config('services.zoho.accounts_url') ?: env('ZOHO_ACCOUNTS_URL')) ?? self::GLOBAL_ACCOUNTS_URL;
    }

    /**
     * Resolve Accounts URL from callback parameters (accounts-server, location).
     */
    public static function resolveAccountsUrl(?string $accountsServer, ?string $location): ?string
    {
        if ($accountsServer !== null && trim($accountsServer) !== '') {
            return self::validateAccountsUrl($accountsServer);
        }

        $loc = strtolower(trim((string) $location));
        if ($loc !== '' && isset(self::LOCATION_MAP[$loc])) {
            return "https://" . self::LOCATION_MAP[$loc];
        }

        $defaultEnvUrl = self::validateAccountsUrl(config('services.zoho.accounts_url') ?: env('ZOHO_ACCOUNTS_URL'));
        if ($defaultEnvUrl !== null) {
            return $defaultEnvUrl;
        }

        return self::GLOBAL_ACCOUNTS_URL;
    }

    /**
     * Resolve corresponding Accounts URL from a validated API host/url.
     */
    public static function getAccountsUrlForApiUrl(string $apiUrl): string
    {
        $parts = parse_url($apiUrl);
        $apiHost = strtolower($parts['host'] ?? '');

        foreach (self::ALLOWED_DATACENTERS as $accountsHost => $allowedApiHost) {
            if ($allowedApiHost === $apiHost) {
                return "https://{$accountsHost}";
            }
        }

        return self::GLOBAL_ACCOUNTS_URL;
    }
}
