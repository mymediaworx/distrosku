<?php

namespace App\RulesHub;

use RuntimeException;

/**
 * Signed rules package. File format (JSON):
 *   {"format":"vega-rules-signed/1","key_id":"...","payload":"<base64 JSON>","signature":"<base64 Ed25519 detached sig of payload bytes>"}
 * Payload JSON:
 *   {"format":"vega-rules/1","version":"2026.10.20.1","issued_at":"...","rules":[...],"tax_rates":[...]}
 * Each rule / rate carries a stable "id" (hub_id) so re-syncs update instead of duplicating.
 */
final class RulesPackage
{
    public const FORMAT = 'vega-rules/1';

    public const SIGNED_FORMAT = 'vega-rules-signed/1';

    public static function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return ['public' => base64_encode(sodium_crypto_sign_publickey($kp)), 'secret' => base64_encode(sodium_crypto_sign_secretkey($kp))];
    }

    public static function sign(array $payload, string $secretKeyB64, string $keyId = 'hub-1'): string
    {
        self::validatePayload($payload);
        $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $sk = base64_decode(trim($secretKeyB64), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Invalid Ed25519 secret key.');
        }

        return json_encode([
            'format' => self::SIGNED_FORMAT, 'key_id' => $keyId,
            'payload' => base64_encode($bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $sk)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Verify and decode. Throws on any problem. */
    public static function verify(string $signedJson, string $publicKeyB64): array
    {
        $env = json_decode($signedJson, true);
        if (! is_array($env) || ($env['format'] ?? null) !== self::SIGNED_FORMAT || ! isset($env['payload'], $env['signature'])) {
            throw new RuntimeException('Not a signed rules package.');
        }
        $pk = base64_decode(trim($publicKeyB64), true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Rules Hub public key is missing or invalid.');
        }
        $bytes = base64_decode($env['payload'], true);
        $sig = base64_decode($env['signature'], true);
        if ($bytes === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, $bytes, $pk)) {
            throw new RuntimeException('Signature verification FAILED. Package rejected.');
        }
        $payload = json_decode($bytes, true);
        self::validatePayload($payload);

        return $payload;
    }

    public static function validatePayload(mixed $p): void
    {
        if (! is_array($p) || ($p['format'] ?? null) !== self::FORMAT || ! preg_match('/^\d+(\.\d+){0,3}$/', (string) ($p['version'] ?? ''))) {
            throw new RuntimeException('Payload must have format "'.self::FORMAT.'" and a numeric dotted version.');
        }
        foreach (['rules', 'tax_rates'] as $k) {
            if (isset($p[$k]) && ! is_array($p[$k])) {
                throw new RuntimeException("$k must be a list.");
            }
            foreach ($p[$k] ?? [] as $i => $row) {
                if (empty($row['id']) || empty($row['jurisdiction']) || empty($row['effective_from'])) {
                    throw new RuntimeException("$k[$i] needs id, jurisdiction and effective_from.");
                }
            }
        }
    }
}
