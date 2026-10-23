<?php

namespace App\Dba;

/**
 * 0.20.8: HMAC-SHA256 request signing shared by the ERP and the instance manager (dba.distrosku.com).
 * Canonical string: METHOD \n /path \n unix-timestamp \n nonce \n sha256(body). Same file ships in both apps.
 */
final class Signature
{
    public const WINDOW = 300; // seconds a signed request stays valid (replay window)

    public static function canonical(string $method, string $path, string $ts, string $nonce, string $body): string
    {
        return strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".$ts."\n".$nonce."\n".hash('sha256', $body);
    }

    public static function sign(string $secret, string $method, string $path, string $ts, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::canonical($method, $path, $ts, $nonce, $body), $secret);
    }

    /** @return array<string,string> headers for an outgoing request */
    public static function headers(string $secret, string $method, string $path, string $body, ?int $now = null): array
    {
        $ts = (string) ($now ?? time());
        $nonce = bin2hex(random_bytes(16));

        return ['X-Dba-Timestamp' => $ts, 'X-Dba-Nonce' => $nonce, 'X-Dba-Signature' => self::sign($secret, $method, $path, $ts, $nonce, $body)];
    }

    /** null when valid, otherwise the reason. Nonce uniqueness (replay) is checked by the caller. */
    public static function check(string $secret, string $method, string $path, ?string $ts, ?string $nonce, ?string $sig, string $body, ?int $now = null, int $window = self::WINDOW): ?string
    {
        if ($secret === '') {
            return 'not linked';
        }
        if (! $ts || ! $nonce || ! $sig || ! ctype_digit($ts) || ! preg_match('/^[A-Fa-f0-9]{16,64}$/', $nonce)) {
            return 'missing signature headers';
        }
        if (abs(($now ?? time()) - (int) $ts) > $window) {
            return 'stale timestamp';
        }
        if (! hash_equals(self::sign($secret, $method, $path, $ts, $nonce, $body), strtolower($sig))) {
            return 'bad signature';
        }

        return null;
    }
}
