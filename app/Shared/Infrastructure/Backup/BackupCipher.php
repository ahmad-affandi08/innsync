<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;
use Generator;

/**
 * Chunked libsodium secretstream (XChaCha20-Poly1305). Streams, so a database dump is never held in memory
 * or written to disk as plaintext. Each chunk is authenticated and the final tag detects truncation.
 *
 * File layout: "ISBK1" | 24-byte stream header | repeat( uint32-be length | ciphertext ).
 */
final class BackupCipher
{
    private const MAGIC = 'ISBK1';

    private const CHUNK = 65536;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw BackupFailed::notConfigured('encryption key must be 32 bytes, base64 encoded');
        }
    }

    public static function fromConfig(): self
    {
        $encoded = config('backup.encryption_key');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($key === false) {
            throw BackupFailed::notConfigured('encryption key');
        }

        return new self($key);
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
    }

    /** Hex HMAC over a canonical manifest, bound to the same secret as the data. */
    public function sign(string $message): string
    {
        return hash_hmac('sha256', $message, $this->key);
    }

    public function encryptTo(string $destination): EncryptingWriter
    {
        return new EncryptingWriter($destination, $this->key, self::MAGIC, self::CHUNK);
    }

    /** @return Generator<int, string> plaintext chunks; throws BackupFailed on tamper or truncation */
    public function decryptFrom(string $source): Generator
    {
        $handle = fopen($source, 'rb');

        if ($handle === false) {
            throw BackupFailed::integrity('encrypted file is unreadable');
        }

        try {
            if (fread($handle, strlen(self::MAGIC)) !== self::MAGIC) {
                throw BackupFailed::integrity('unknown file format');
            }

            $header = fread($handle, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if ($header === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw BackupFailed::integrity('truncated header');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            $final = false;

            while (! feof($handle)) {
                $length = fread($handle, 4);

                if ($length === '' || $length === false) {
                    break;
                }

                if ($final || strlen($length) !== 4) {
                    throw BackupFailed::integrity('unexpected trailing data');
                }

                $size = unpack('N', $length)[1];
                $cipher = $this->readExactly($handle, $size);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

                if ($result === false) {
                    throw BackupFailed::integrity('chunk authentication failed');
                }

                [$plain, $tag] = $result;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

                yield $plain;
            }

            if (! $final) {
                throw BackupFailed::integrity('stream is truncated');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function readExactly($handle, int $size): string
    {
        $data = '';

        while (strlen($data) < $size) {
            $part = fread($handle, $size - strlen($data));

            if ($part === false || $part === '') {
                throw BackupFailed::integrity('truncated chunk');
            }

            $data .= $part;
        }

        return $data;
    }
}
