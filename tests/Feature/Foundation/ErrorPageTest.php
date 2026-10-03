<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** A person who opens a screen they may not use sees a page that says so, in the frame of the application; programs keep the JSON envelope. */
final class ErrorPageTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    private function signIn(): void
    {
        $this->createProperty(self::A, 'A');
        $user = UserRecord::factory()->create();
        $this->grant($user, self::A, []);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    public function test_a_screen_the_person_may_not_use_is_an_error_page_with_the_status(): void
    {
        $this->signIn();

        $this->get('/finance/payables')->assertForbidden()->assertInertia(fn (Assert $page) => $page
            ->component('foundation/pages/error')
            ->where('status', 403)
            ->where('message', 'You are not allowed to perform this action.'));
    }

    public function test_the_same_page_comes_for_a_visit_made_by_the_application(): void
    {
        $this->signIn();

        $this->get('/finance/payables', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())])
            ->assertForbidden()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'foundation/pages/error')->assertJsonPath('props.status', 403);
    }

    public function test_programs_still_get_the_json_envelope(): void
    {
        $this->signIn();

        $this->getJson('/finance/payables')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    }
}
