<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyOperationIncomplete;
use App\Shared\Application\Tenancy\MissingPropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ErrorEnvelopeTest extends TestCase
{
    private const CORRELATION_ID = '01arz3ndektsv4rrffq69g5fav';

    /** @return iterable<string, array{0: \Closure(): \Throwable, 1: int, 2: string}> */
    public static function exceptions(): iterable
    {
        yield 'optimistic lock' => [fn () => OptimisticLockConflict::forRecord('folio', 'secret-id', 3), 409, 'conflict'];
        yield 'idempotency mismatch' => [fn () => IdempotencyConflict::requestMismatch(), 409, 'conflict'];
        yield 'idempotency in progress' => [fn () => IdempotencyOperationIncomplete::detected(), 409, 'conflict'];
        yield 'file rejected' => [fn () => FileRejected::unsupportedType(), 422, 'file_rejected'];
        yield 'file denied' => [fn () => FileAccessDenied::forFile('secret-id'), 403, 'forbidden'];
        yield 'cross property' => [fn () => PropertyScopeViolation::mismatched('secret-a', 'secret-b'), 403, 'forbidden'];
        yield 'missing property' => [fn () => MissingPropertyContext::forScopedOperation(), 403, 'property_context_required'];
        yield 'authorization' => [fn () => new AuthorizationException('secret detail'), 403, 'forbidden'];
        yield 'unexpected' => [fn () => new RuntimeException('SQLSTATE secret password=hunter2'), 500, 'server_error'];
    }

    /** @param \Closure(): \Throwable $make */
    #[DataProvider('exceptions')]
    public function test_json_errors_use_the_envelope_without_leaking_internals(\Closure $make, int $status, string $code): void
    {
        Route::post('/_test/boom', fn () => throw $make());

        $response = $this->withHeaders(['Accept' => 'application/json', 'X-Correlation-ID' => self::CORRELATION_ID])
            ->post('/_test/boom')
            ->assertStatus($status)
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID)
            ->assertJsonPath('error.code', $code)
            ->assertJsonPath('error.status', $status)
            ->assertJsonPath('error.correlation_id', self::CORRELATION_ID);

        $body = $response->getContent();
        foreach (['secret', 'hunter2', 'SQLSTATE', 'folio', 'Exception', '.php'] as $leak) {
            self::assertStringNotContainsString($leak, (string) $body);
        }
    }

    public function test_conflicts_carry_reason_and_user_action(): void
    {
        Route::post('/_test/lock', fn () => throw OptimisticLockConflict::forRecord('folio', 'x', 1));

        $this->postJson('/_test/lock')
            ->assertConflict()
            ->assertJsonPath('error.conflict.reason', 'optimistic_lock')
            ->assertJsonPath('error.conflict.action', 'refresh');

        Route::post('/_test/inflight', fn () => throw IdempotencyOperationIncomplete::detected());

        $this->postJson('/_test/inflight')
            ->assertConflict()
            ->assertJsonPath('error.retryable', true)
            ->assertJsonPath('error.conflict.action', 'retry');
    }

    public function test_validation_errors_list_fields(): void
    {
        Route::post('/_test/validate', fn (Request $r) => $r->validate(['name' => 'required']));

        $this->postJson('/_test/validate')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['name']]]);
    }

    public function test_http_errors_are_normalized_and_keep_safe_headers(): void
    {
        $this->getJson('/_test/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        Route::get('/_test/get-only', fn () => 'ok');
        $this->postJson('/_test/get-only')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed')
            ->assertHeader('Allow');

        Route::get('/_test/auth', fn () => throw new AuthenticationException);
        $this->getJson('/_test/auth')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

        $this->post('/_test/idempotency')->assertStatus(404);
    }

    public function test_validation_exception_without_json_keeps_framework_redirect(): void
    {
        Route::middleware('web')->post('/_test/web-validate', fn () => throw ValidationException::withMessages(['a' => 'bad']));

        $this->from('/login')->post('/_test/web-validate')->assertRedirect('/login')->assertSessionHasErrors('a');
    }

    public function test_inertia_mutation_conflict_redirects_back_with_a_message(): void
    {
        Route::middleware('web')->post('/_test/inertia-lock', fn () => throw OptimisticLockConflict::forRecord('folio', 'x', 1));

        $this->from('/login')
            ->withHeader('X-Inertia', 'true')
            ->post('/_test/inertia-lock')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('error');
    }

    public function test_expected_outcomes_are_not_reported_as_defects(): void
    {
        $handler = app(ExceptionHandler::class);

        self::assertFalse($handler->shouldReport(OptimisticLockConflict::forRecord('a', 'b', 1)));
        self::assertTrue($handler->shouldReport(new RuntimeException('defect')));
    }
}
