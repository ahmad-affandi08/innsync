<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Push;

/** The address a browser gives for one of its devices. It is called by this server, so only the addresses of the known push services are taken (never an address of a person's choosing). */
final class PushEndpoint
{
    public static function acceptable(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443) || strlen($endpoint) > 700) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ((array) config('push.allowed_hosts') as $allowed) {
            $allowed = strtolower((string) $allowed);

            if ($allowed[0] === '.' ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }
}
