<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Idempotency\IdempotencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class IdempotencyMiddlewareTest extends TestCase
{
    private const KEY = '01arz3ndektsv4rrffq69g5fax';

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/_test/idempotency', static function (Request $request) {
            return response()->json([
                'key' => app(IdempotencyContext::class)->current()->toString(),
                'key_hash' => Context::get('idempotency_key_hash'),
                'body' => $request->string('value')->toString(),
            ]);
        })->middleware('idempotent');
    }

    public function test_missing_or_invalid_header_is_rejected_before_the_action(): void
    {
        $this->post('/_test/idempotency')->assertBadRequest();
        $this->withHeader('Idempotency-Key', 'unsafe key')
            ->post('/_test/idempotency')
            ->assertBadRequest();
    }

    public function test_valid_header_is_available_during_request_without_raw_log_context(): void
    {
        $response = $this->withHeader('Idempotency-Key', self::KEY)
            ->post('/_test/idempotency', ['value' => 'accepted'])
            ->assertOk()
            ->assertJson([
                'key' => self::KEY,
                'body' => 'accepted',
            ]);

        $hash = $response->json('key_hash');

        self::assertIsString($hash);
        self::assertSame(64, strlen($hash));
        self::assertNotSame(self::KEY, $hash);
        self::assertFalse(app(IdempotencyContext::class)->hasActiveKey());
    }
}
