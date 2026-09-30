<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Tenancy;

use App\Shared\Application\Tenancy\MissingPropertyContext;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PropertyContextTest extends TestCase
{
    public function test_property_id_validates_and_normalizes_a_ulid(): void
    {
        $propertyId = PropertyId::fromString('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        self::assertSame('01arz3ndektsv4rrffq69g5fav', $propertyId->toString());
        self::assertTrue($propertyId->equals(PropertyId::fromString($propertyId->toString())));
    }

    public function test_invalid_property_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PropertyId::fromString('not-a-ulid');
    }

    public function test_context_fails_closed_and_restores_nested_contexts(): void
    {
        $context = new PropertyContext;
        $first = PropertyId::fromString('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $second = PropertyId::fromString('01ARZ3NDEKTSV4RRFFQ69G5FAW');

        $context->run($first, function () use ($context, $first, $second): void {
            self::assertTrue($context->current()->equals($first));

            $context->run($second, function () use ($context, $second): void {
                self::assertTrue($context->current()->equals($second));
            });

            self::assertTrue($context->current()->equals($first));
        });

        $this->expectException(MissingPropertyContext::class);

        $context->current();
    }
}
