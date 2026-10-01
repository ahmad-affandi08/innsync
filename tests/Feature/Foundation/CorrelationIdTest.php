<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CorrelationIdTest extends TestCase
{
    private const CORRELATION_ID = '01arz3ndektsv4rrffq69g5fav';

    public function test_valid_incoming_correlation_id_is_propagated_to_response(): void
    {
        $this->withHeader('X-Correlation-ID', strtoupper(self::CORRELATION_ID))
            ->get('/login')
            ->assertOk()
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID);
    }

    public function test_missing_or_invalid_correlation_id_is_replaced_with_a_ulid(): void
    {
        $response = $this->withHeader('X-Correlation-ID', "invalid\nheader")
            ->get('/login')
            ->assertOk();

        $correlationId = $response->headers->get('X-Correlation-ID');

        self::assertIsString($correlationId);
        self::assertTrue(Str::isUlid($correlationId));
        self::assertSame(strtolower($correlationId), $correlationId);
    }

    public function test_rendered_exception_response_keeps_the_correlation_id(): void
    {
        Route::get('/_test/correlation-exception', static fn () => abort(418));

        $this->withHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->get('/_test/correlation-exception')
            ->assertStatus(418)
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID);
    }
}
