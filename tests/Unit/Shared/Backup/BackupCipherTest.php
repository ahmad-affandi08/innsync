<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Backup;

use App\Shared\Application\Backup\BackupFailed;
use App\Shared\Infrastructure\Backup\BackupCipher;
use PHPUnit\Framework\TestCase;

final class BackupCipherTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/cipher-'.bin2hex(random_bytes(6)).'.enc';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_round_trips_data_larger_than_one_chunk_without_leaking_plaintext(): void
    {
        $cipher = new BackupCipher(random_bytes(32));
        $plain = str_repeat("CREATE TABLE secret_guest_names;\n", 8000);

        $writer = $cipher->encryptTo($this->file);
        foreach (str_split($plain, 10_000) as $part) {
            $writer->write($part);
        }
        self::assertSame(strlen($plain), $writer->close());

        self::assertStringNotContainsString('secret_guest_names', (string) file_get_contents($this->file));
        self::assertSame($plain, implode('', iterator_to_array($cipher->decryptFrom($this->file), false)));
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));
    }

    public function test_empty_stream_is_valid(): void
    {
        $cipher = new BackupCipher(random_bytes(32));
        $cipher->encryptTo($this->file)->close();

        self::assertSame('', implode('', iterator_to_array($cipher->decryptFrom($this->file), false)));
    }

    public function test_wrong_key_tampering_and_truncation_are_detected(): void
    {
        $key = random_bytes(32);
        $cipher = new BackupCipher($key);
        $writer = $cipher->encryptTo($this->file);
        $writer->write(str_repeat('x', 200_000));
        $writer->close();
        $good = (string) file_get_contents($this->file);

        $attempts = [
            'wrong key' => [new BackupCipher(random_bytes(32)), $good],
            'bit flip' => [$cipher, substr_replace($good, chr(ord($good[1000]) ^ 1), 1000, 1)],
            'truncated' => [$cipher, substr($good, 0, strlen($good) - 40)],
            'dropped tail chunk' => [$cipher, substr($good, 0, 5 + 24 + 4 + 65536 + 17)],
            'trailing junk' => [$cipher, $good."\x00\x00\x00\x05abcde"],
            'not a backup' => [$cipher, 'plain text file'],
        ];

        foreach ($attempts as $label => [$reader, $bytes]) {
            file_put_contents($this->file, $bytes);

            try {
                iterator_to_array($reader->decryptFrom($this->file));
                self::fail($label.' was accepted.');
            } catch (BackupFailed) {
                self::assertTrue(true);
            }
        }
    }

    public function test_key_must_be_32_bytes(): void
    {
        $this->expectException(BackupFailed::class);

        new BackupCipher('short');
    }

    public function test_signatures_depend_on_the_key(): void
    {
        $a = new BackupCipher(str_repeat('a', 32));
        $b = new BackupCipher(str_repeat('b', 32));

        self::assertSame($a->sign('m'), $a->sign('m'));
        self::assertNotSame($a->sign('m'), $b->sign('m'));
    }
}
