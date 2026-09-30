<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FoundationSmokeTest extends TestCase
{
    public function test_guests_are_sent_to_the_inertia_login_page(): void
    {
        $this->withoutVite();

        $this->get('/')->assertRedirect('/login');
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('identity-access/pages/login')
                ->where('app.name', 'InnSYnc'));
    }

    public function test_authentication_record_and_factory_use_the_identity_infrastructure_namespace(): void
    {
        self::assertSame(UserRecord::class, config('auth.providers.users.model'));
        self::assertInstanceOf(UserRecord::class, UserRecord::factory()->make());
    }
}
