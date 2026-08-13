<?php

namespace App\Services;

class ZohoDatacenter
{
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

        $defaultEnvUrl = self::validateAccountsUrl(env('ZOHO_ACCOUNTS_URL'));
        if ($defaultEnvUrl !== null) {
            return $defaultEnvUrl;
        }

        return 'https://accounts.zoho.com';
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

        return 'https://accounts.zoho.com';
    }
}
