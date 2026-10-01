<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Idempotency;

use App\Shared\Application\Idempotency\IdempotencyResultUnreadable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;
use LogicException;

final readonly class IdempotencyPayloadCodec
{
    public function __construct(private Encrypter $encrypter) {}

    public function keyHash(string $key): string
    {
        return hash_hmac('sha256', $key, $this->hashKey());
    }

    /** @param array<string, mixed> $payload */
    public function requestHash(array $payload): string
    {
        return hash_hmac(
            'sha256',
            $this->canonicalJson($payload),
            $this->hashKey(),
        );
    }

    /** @param array<string, mixed> $payload */
    public function encryptResult(array $payload): string
    {
        return $this->encrypter->encryptString($this->canonicalJson($payload));
    }

    /** @return array<string, mixed> */
    public function decryptResult(string $encryptedPayload): array
    {
        try {
            $payload = json_decode(
                $this->encrypter->decryptString($encryptedPayload),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            throw IdempotencyResultUnreadable::detected();
        }

        if (! is_array($payload)) {
            throw IdempotencyResultUnreadable::detected();
        }

        return $payload;
    }

    /** @param array<array-key, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        return json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        return $payload;
    }

    private function hashKey(): string
    {
        $key = config('idempotency.hash_key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('IDEMPOTENCY_HASH_KEY or APP_KEY must be configured.');
        }

        return $key;
    }
}
