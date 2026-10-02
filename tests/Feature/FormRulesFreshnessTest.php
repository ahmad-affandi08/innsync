<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Router;
use Tests\Support\FormRuleExtractor;
use Tests\TestCase;

/**
 * The screens mark required fields from `resources/js/generated/route-rules.json`, which is read from the
 * controllers. This fails when a validation rule changed and the file did not follow.
 */
final class FormRulesFreshnessTest extends TestCase
{
    public function test_the_generated_route_rules_match_the_controllers(): void
    {
        $fresh = (new FormRuleExtractor)->extract($this->app->make(Router::class));
        $committed = json_decode((string) file_get_contents(base_path('resources/js/generated/route-rules.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            $fresh,
            $committed,
            'Validation rules changed. Run `php tools/export-form-rules.php` and then `npm run forms`, and commit both generated files.',
        );
    }

    public function test_the_main_forms_are_read_with_their_required_fields(): void
    {
        $rules = (new FormRuleExtractor)->extract($this->app->make(Router::class));

        $this->assertContains('guest_name', $rules['POST /front-office/reservations']['required']);
        $this->assertNotContains('notes', $rules['POST /front-office/reservations']['required']);
        $this->assertGreaterThan(150, count($rules));
    }
}
