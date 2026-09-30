<?php

declare(strict_types=1);

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
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
}
