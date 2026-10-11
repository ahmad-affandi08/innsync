<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Infrastructure\Push\Vapid;
use PHPUnit\Framework\TestCase;

final class VapidTest extends TestCase
{
    private static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }

    public function test_the_token_is_a_signed_es256_jwt_that_names_the_push_service_and_the_contact(): void
    {
        $keys = Vapid::generate();
        $vapid = new Vapid($keys['private'], 'mailto:hotel@example.com');

        self::assertTrue($vapid->enabled());
        self::assertSame($keys['public'], $vapid->publicKey());

        $point = self::unb64($keys['public']);
        self::assertSame(65, strlen($point));
        self::assertSame(4, ord($point[0]));

        $header = $vapid->authorization('https://fcm.googleapis.com/fcm/send/abc', 1_800_000_000);
        self::assertSame(1, preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/D', $header, $m));
        self::assertSame($keys['public'], $m[4]);
        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(self::unb64($m[1]), true));
        self::assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_000_000 + 43_200, 'sub' => 'mailto:hotel@example.com'], json_decode(self::unb64($m[2]), true));

        // The signature is the two numbers side by side (64 bytes) and verifies with the public key once put back into DER form.
        $raw = self::unb64($m[3]);
        self::assertSame(64, strlen($raw));
        $integer = static fn (string $n): string => "\x02".chr(strlen($n = (ord($n[0]) & 0x80 ? "\0" : '').ltrim($n, "\0"))).$n;
        $body = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));
        $der = "\x30".chr(strlen($body)).$body;
        $pem = (string) base64_decode($keys['private'], true);
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
        self::assertSame(1, openssl_verify($m[1].'.'.$m[2], $der, $details['key'], OPENSSL_ALGO_SHA256));
        self::assertSame(0, openssl_verify($m[1].'.'.$m[2].'x', $der, $details['key'], OPENSSL_ALGO_SHA256));
    }

    public function test_a_der_signature_with_short_numbers_is_padded_to_thirty_two_bytes_each(): void
    {
        $r = "\x01\x02";
        $s = "\xff".str_repeat("\x07", 31);
        $der = "\x30".chr(2 + 2 + 2 + 33)."\x02\x02".$r."\x02\x21\x00".$s;

        self::assertSame(str_repeat("\0", 30).$r.$s, Vapid::rawSignature($der));
    }

    public function test_without_a_key_or_a_contact_push_is_off(): void
    {
        self::assertFalse((new Vapid('', 'mailto:a@b.c'))->enabled());
        self::assertFalse((new Vapid(base64_encode('not a key'), 'mailto:a@b.c'))->enabled());
        self::assertFalse((new Vapid(Vapid::generate()['private'], ''))->enabled());
    }
}
