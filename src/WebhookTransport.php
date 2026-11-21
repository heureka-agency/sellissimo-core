<?php

namespace Sellissimo\Core;

use RuntimeException;

class WebhookTransport
{
    public static function host(string $url): ?string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) || ! str_contains($host, '.')
            || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $host;
    }

    public function post(string $url, string $body, array $headers): int
    {
        $host = self::host($url) ?? throw new RuntimeException('Invalid delivery URL.');
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('The endpoint requires a public address.');
            }
            $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        }
        if (! $addresses || ! function_exists('curl_init')) {
            throw new RuntimeException('The delivery endpoint could not be reached.');
        }
        $port = parse_url($url, PHP_URL_PORT) ?: 443;
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12,
            CURLOPT_RESOLVE => ["$host:$port:".$addresses[0]], CURLOPT_WRITEFUNCTION => static fn ($handle, $chunk) => strlen($chunk)]);
        if (curl_exec($curl) === false) {
            throw new RuntimeException('Delivery connection failed.');
        }

        return (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    }
}
