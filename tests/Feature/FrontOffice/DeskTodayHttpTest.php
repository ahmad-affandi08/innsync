<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class DeskTodayHttpTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->createProperty(self::A, 'A');
    }

    private function openDay(): void
    {
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    public function test_the_front_desk_day_page_shows_counts_for_someone_who_may_see_stays(): void
    {
        $this->signIn(self::A, ['front-office.stay.view', 'property.settings.manage']);
        $this->openDay();

        $this->get('/front-office/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('front-office/pages/today')
            ->where('desk.reminders_due', 0)
            ->has('desk.arrivals', 0)
            ->has('desk.departures', 0));
    }

    public function test_someone_without_front_office_permission_is_refused(): void
    {
        $this->signIn(self::A, ['finance.payables.view']);

        $this->get('/front-office/today')->assertForbidden();
    }

    public function test_a_person_of_one_department_lands_on_it_and_home_flag_shows_the_home_page(): void
    {
        $this->signIn(self::A, ['front-office.stay.view']);

        $this->get('/')->assertRedirect('/front-office/today');
        $this->get('/?home=1')->assertOk()->assertInertia(fn (Assert $page) => $page->component('foundation/pages/welcome'));
    }

    public function test_a_person_of_two_departments_sees_the_home_page(): void
    {
        $this->signIn(self::A, ['front-office.stay.view', 'finance.payables.view']);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('foundation/pages/welcome'));
    }

    public function test_work_links_page_needs_the_settings_permission_and_lists_guest_links(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);
        $this->get('/property/department-links')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('foundation/pages/department-links')
            ->where('guest.rooms', '/guest/qr')
            ->where('guest.booking', '/book/'.self::A)
            ->has('departments'));
    }

    public function test_work_links_page_is_refused_without_the_settings_permission(): void
    {
        $this->signIn(self::A, ['front-office.stay.view']);

        $this->get('/property/department-links')->assertForbidden();
    }
}
