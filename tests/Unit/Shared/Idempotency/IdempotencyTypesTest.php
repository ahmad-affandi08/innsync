<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Idempotency;

use App\Shared\Application\Idempotency\IdempotencyContext;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IdempotencyTypesTest extends TestCase
{
    private const KEY = '01arz3ndektsv4rrffq69g5fax';

    private const PROPERTY_ID = '01arz3ndektsv4rrffq69g5fav';

    public function test_key_and_request_accept_safe_transport_neutral_values(): void
    {
        $key = IdempotencyKey::fromString(self::KEY);
        $request = new IdempotencyRequest(
            PropertyId::fromString(self::PROPERTY_ID),
            $key,
            'front-office.checkout',
            ['folio_id' => '01arz3ndektsv4rrffq69g5fay'],
            '01arz3ndektsv4rrffq69g5faz',
        );

        self::assertSame(self::KEY, $key->toString());
        self::assertSame('front-office.checkout', $request->operation);
    }

    public function test_unsafe_or_short_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKey::fromString('short key');
    }

    public function test_invalid_operation_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IdempotencyRequest(
            PropertyId::fromString(self::PROPERTY_ID),
            IdempotencyKey::fromString(self::KEY),
            'Invalid Operation',
            [],
        );
    }

    public function test_context_fails_closed_and_can_be_cleared(): void
    {
        $context = new IdempotencyContext;

        self::assertFalse($context->hasActiveKey());

        $context->activate(IdempotencyKey::fromString(self::KEY));
        self::assertSame(self::KEY, $context->current()->toString());

        $context->clear();

        $this->expectException(RuntimeException::class);
        $context->current();
    }
}
