<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Backup\BackupFailed;

final class EncryptingWriter
{
    /** @var resource */
    private $handle;

    private string $state;

    private string $buffer = '';

    private int $plainBytes = 0;

    private bool $closed = false;

    public function __construct(
        string $destination,
        private readonly string $key,
        string $magic,
        private readonly int $chunkSize,
    ) {
        $handle = fopen($destination, 'xb');

        if ($handle === false) {
            throw BackupFailed::integrity('cannot create encrypted file');
        }

        chmod($destination, 0600);
        $this->handle = $handle;
        [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($this->handle, $magic.$header);
    }

    public function write(string $plain): void
    {
        $this->plainBytes += strlen($plain);
        $this->buffer .= $plain;

        while (strlen($this->buffer) > $this->chunkSize) {
            $this->emit(substr($this->buffer, 0, $this->chunkSize), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->buffer = substr($this->buffer, $this->chunkSize);
        }
    }

    /** Finishes the stream with the FINAL tag. Returns plaintext byte count. */
    public function close(): int
    {
        if (! $this->closed) {
            $this->emit($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->buffer = '';
            fflush($this->handle);
            fclose($this->handle);
            $this->closed = true;
            sodium_memzero($this->state);
        }

        return $this->plainBytes;
    }

    public function abort(): void
    {
        if (! $this->closed) {
            fclose($this->handle);
            $this->closed = true;
        }
    }

    private function emit(string $plain, int $tag): void
    {
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);
        fwrite($this->handle, pack('N', strlen($cipher)).$cipher);
    }
}
