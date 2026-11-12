<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Concurrency\OptimisticLockConflict;
use App\Shared\Application\Errors\Refusal;
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
use Illuminate\Support\Facades\Lang;
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

    public function test_a_refusal_with_its_own_code_is_told_in_its_own_translated_sentence(): void
    {
        Route::post('/_test/needs-shift', fn () => throw Refusal::stateConflict('Open your cashier shift before taking a payment.', 'fnb_shift_required'));
        Route::post('/_test/wrong-name', fn () => throw Refusal::invalid('The name does not match the guest in this room.', ['guest_name'], 'room_name_mismatch'));
        Route::post('/_test/plain', fn () => throw Refusal::stateConflict('Something about the state with the secret-id 42.'));
        Route::post('/_test/unknown-code', fn () => throw Refusal::stateConflict('Another state.', 'no_such_sentence'));
        $headers = ['Accept' => 'application/json', 'X-Correlation-ID' => self::CORRELATION_ID];

        foreach (['en', 'id'] as $locale) {
            app()->setLocale($locale);

            $this->withHeaders($headers)->post('/_test/needs-shift')->assertStatus(409)
                ->assertJsonPath('error.reason', 'fnb_shift_required')
                ->assertJsonPath('error.message', __('errors.reason_fnb_shift_required'))
                ->assertJsonPath('error.conflict.reason', 'state');

            $this->withHeaders($headers)->post('/_test/wrong-name')->assertStatus(422)
                ->assertJsonPath('error.reason', 'room_name_mismatch')
                ->assertJsonPath('error.message', __('errors.reason_room_name_mismatch'))
                ->assertJsonPath('error.fields.guest_name.0', __('errors.reason_room_name_mismatch'));
        }

        // The general sentence of the status stays for a refusal without a code or with a code that has no sentence, and the English text of the code never reaches the response.
        foreach (['/_test/plain', '/_test/unknown-code'] as $url) {
            $response = $this->withHeaders($headers)->post($url)->assertStatus(409)->assertJsonPath('error.message', __('errors.conflict_state'));
            self::assertArrayNotHasKey('reason', $response->json('error'));
            self::assertStringNotContainsString('secret-id', (string) $response->getContent());
            self::assertStringNotContainsString('Another state', (string) $response->getContent());
        }
    }

    public function test_every_reason_code_a_refusal_uses_has_a_sentence_in_both_languages(): void
    {
        $codes = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with((string) $file, '.php')) {
                preg_match_all("/Refusal::(?:invalid|stateConflict|forbidden|notFound)\\('[^']*'(?:, \\[[^\\]]*\\])?, '([a-z_]+)'\\)/", (string) file_get_contents((string) $file), $found);
                $codes = [...$codes, ...$found[1]];
            }
        }

        self::assertNotSame([], $codes);

        foreach (array_unique($codes) as $code) {
            foreach (['en', 'id'] as $locale) {
                self::assertTrue(Lang::has("errors.reason_{$code}", $locale, false), "errors.reason_{$code} is missing in {$locale}");
            }
        }
    }
}
