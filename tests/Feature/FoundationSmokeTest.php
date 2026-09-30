<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FoundationSmokeTest extends TestCase
{
    public function test_the_foundation_page_is_served_through_inertia(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('foundation/pages/welcome')
                ->where('appVersion', '0.1.0-dev')
                ->where('app.name', 'InnSYnc'));
    }

    public function test_authentication_record_and_factory_use_the_identity_infrastructure_namespace(): void
    {
        self::assertSame(UserRecord::class, config('auth.providers.users.model'));
        self::assertInstanceOf(UserRecord::class, UserRecord::factory()->make());
    }
}
