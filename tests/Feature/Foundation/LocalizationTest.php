<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Concurrency\OptimisticLockConflict;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LocalizationTest extends TestCase
{
    public function test_default_locale_applies_without_a_preference(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('lang="en"', false);
    }

    public function test_accept_language_selects_indonesian_and_is_shared_with_the_page(): void
    {
        $this->withHeaders(['Accept-Language' => 'id-ID,id;q=0.9,en;q=0.5'])
            ->get('/login')
            ->assertOk()
            ->assertHeader('Content-Language', 'id')
            ->assertSee('lang="id"', false)
            ->assertSee('"locale":"id"', false);
    }

    public function test_unsupported_accept_language_falls_back_to_the_default(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr-FR,de;q=0.8'])
            ->get('/login')
            ->assertHeader('Content-Language', 'en');
    }

    public function test_the_switcher_choice_is_stored_and_beats_accept_language(): void
    {
        $this->from('http://localhost/login')
            ->withHeaders(['Accept-Language' => 'en'])
            ->post('/locale', ['locale' => 'id'])
            ->assertRedirect('http://localhost/login')
            ->assertSessionHas('locale', 'id');

        $this->withHeaders(['Accept-Language' => 'en'])
            ->get('/login')
            ->assertHeader('Content-Language', 'id');
    }

    public function test_an_unsupported_locale_is_rejected_and_not_stored(): void
    {
        $this->post('/locale', ['locale' => 'fr'])
            ->assertSessionHasErrors('locale')
            ->assertSessionMissing('locale');

        $this->postJson('/locale', ['locale' => '../../etc/passwd'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_the_switcher_never_redirects_to_another_host(): void
    {
        $this->from('https://evil.example/phish')
            ->post('/locale', ['locale' => 'id'])
            ->assertRedirect('/');
    }

    public function test_validation_messages_follow_the_locale(): void
    {
        $this->withHeaders(['Accept-Language' => 'id'])
            ->postJson('/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.email.0', 'Kolom email wajib diisi.');

        $this->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.email.0', 'The email field is required.');
    }

    public function test_error_envelope_message_is_localized_but_code_and_action_stay_neutral(): void
    {
        Route::middleware('web')->post('/_test/locale-conflict', fn () => throw OptimisticLockConflict::forRecord('folio', 'x', 1));

        $id = $this->withHeaders(['Accept' => 'application/json', 'Accept-Language' => 'id'])
            ->post('/_test/locale-conflict')
            ->assertStatus(409);
        $en = $this->withHeaders(['Accept' => 'application/json', 'Accept-Language' => 'en'])
            ->post('/_test/locale-conflict')
            ->assertStatus(409);

        foreach ([$id, $en] as $response) {
            $response->assertJsonPath('error.code', 'conflict')
                ->assertJsonPath('error.conflict.reason', 'optimistic_lock')
                ->assertJsonPath('error.conflict.action', 'refresh');
        }

        self::assertStringContainsString('diubah oleh orang lain', (string) $id->json('error.message'));
        self::assertStringContainsString('changed by someone else', (string) $en->json('error.message'));
    }

    public function test_unexpected_errors_are_localized_without_leaking_details(): void
    {
        Route::middleware('web')->get('/_test/locale-boom', fn () => throw new \RuntimeException('SQLSTATE password=hunter2'));

        $response = $this->withHeaders(['Accept' => 'application/json', 'Accept-Language' => 'id'])
            ->get('/_test/locale-boom')
            ->assertStatus(500)
            ->assertJsonPath('error.message', 'Terjadi kesalahan yang tidak terduga.');

        self::assertStringNotContainsString('hunter2', (string) $response->getContent());
    }
}
