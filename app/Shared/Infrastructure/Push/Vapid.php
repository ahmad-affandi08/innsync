<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Push;

/**
 * The sender's identity towards the push services (VAPID, RFC 8292): a P-256 key pair, and for each call a short-lived signed token that names the push service and a contact. The key is made once with
 * `php artisan innsync:push-keys` and kept in the environment; with it the browser also checks that a push came from this server.
 */
final readonly class Vapid
{
    public function __construct(private string $privateKeyBase64, private string $subject) {}

    public static function fromConfig(): self
    {
        return new self((string) config('push.vapid_private_key'), (string) config('push.vapid_subject'));
    }

    /** @return array{private: string, public: string} the private key as base64 of a PEM (for the environment) and the public key as the browser wants it */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('The key could not be made.');
        }

        return ['private' => base64_encode($pem), 'public' => (new self(base64_encode($pem), ''))->publicKey()];
    }

    public function enabled(): bool
    {
        return $this->pem() !== null && $this->subject !== '';
    }

    /** The public key as an uncompressed point in base64url: what `applicationServerKey` takes. */
    public function publicKey(): string
    {
        $details = $this->pem() === null ? false : openssl_pkey_get_details(openssl_pkey_get_private($this->pem()) ?: throw new \RuntimeException('The key cannot be read.'));

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            return '';
        }

        return self::b64(chr(4).str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT));
    }

    /** The `Authorization` header for a call to the push service at `$endpoint`. */
    public function authorization(string $endpoint, int $now): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $signing = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::b64((string) json_encode(['aud' => $audience, 'exp' => $now + 12 * 3600, 'sub' => $this->subject]));

        if ($this->pem() === null || ! openssl_sign($signing, $der, $this->pem(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The token could not be signed.');
        }

        return 'vapid t='.$signing.'.'.self::b64(self::rawSignature($der)).', k='.$this->publicKey();
    }

    /** The two numbers of a DER signature, each as 32 bytes, one after the other (what a JWT carries). */
    public static function rawSignature(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $numbers = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $numbers[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $numbers[0].$numbers[1];
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function pem(): ?string
    {
        $pem = base64_decode($this->privateKeyBase64, true);

        return $pem === false || ! str_contains($pem, 'PRIVATE KEY') ? null : $pem;
    }
}
