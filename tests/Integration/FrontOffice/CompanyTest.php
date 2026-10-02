<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Companies\CompanyRouting;
use App\Modules\FrontOffice\Application\Companies\CompanyService;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Reporting\Application\DashboardService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FO-035: company and agent profiles, credit limit, billing instruction and routing of charges to a company folio. */
final class CompanyTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private int $key = 0;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHotel();
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function companies(): CompanyService
    {
        return app(CompanyService::class);
    }

    private function refused(callable $do, int $status): void
    {
        try {
            $do();
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function company(string $code = 'ACME', array $extra = []): array
    {
        return $this->companies()->create($this->property(), $this->companyManagerId, [...['code' => $code, 'name' => 'PT Acme Travel', 'kind' => 'company', 'tax_id' => '01.234.567.8-901.000', 'billing_instruction' => 'Send the invoice to finance@acme.test', 'credit_limit_minor' => 500_000_000, 'route_rooms' => true, 'route_extras' => false], ...$extra]);
    }

    /** @return array{reservation: string, stay: string} */
    private function inHouse(int $room = 0): array
    {
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $stay = app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[$room], 'Budi Santoso', 'ID', 'ktp', sprintf('31740101019%05d', $room + 1), null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString(sprintf('company-checkin-%04d', ++$this->key)))['id'];

        return ['reservation' => $reservation->id, 'stay' => $stay];
    }

    /** @return list<array{id: string, label: string, window: int, balance: int}> */
    private function folios(string $reservation): array
    {
        return array_map(static fn ($f): array => ['id' => $f->id, 'label' => $f->label, 'window' => $f->window, 'balance' => $f->balance->amountMinor], app(FolioRepository::class)->byReservation($this->property(), $reservation));
    }

    public function test_profiles_are_validated_unique_by_code_and_changed_with_a_reason(): void
    {
        $this->refused(fn () => $this->companies()->create($this->property(), $this->managerId, ['code' => 'ACME', 'name' => 'x', 'kind' => 'company']), 403);
        $this->refused(fn () => $this->company('A'), 422);
        $this->refused(fn () => $this->company('ACME', ['name' => ' ']), 422);
        $this->refused(fn () => $this->company('ACME', ['kind' => 'club']), 422);
        $this->refused(fn () => $this->company('ACME', ['credit_limit_minor' => -1]), 422);
        $this->refused(fn () => $this->company('ACME', ['contact_email' => 'not-an-address']), 422);

        $acme = $this->company('acme');
        self::assertSame(['ACME', 'company', true, true, false, 0], [$acme['code'], $acme['kind'], $acme['is_active'], $acme['route_rooms'], $acme['route_extras'], $acme['lock_version']]);
        $this->refused(fn () => $this->company('ACME'), 422);

        $this->refused(fn () => $this->companies()->update($this->property(), $this->companyViewerId, $acme['id'], ['name' => 'PT Acme', 'kind' => 'company'], true, 0, 'Rename'), 403);
        $this->refused(fn () => $this->companies()->update($this->property(), $this->companyManagerId, $acme['id'], ['name' => 'PT Acme', 'kind' => 'company'], true, 0, ' '), 422);
        $updated = $this->companies()->update($this->property(), $this->companyManagerId, $acme['id'], ['name' => 'PT Acme', 'kind' => 'company', 'credit_limit_minor' => 900_000_000, 'route_rooms' => true, 'route_extras' => true], true, 0, 'Agreed with the company');
        self::assertSame([900_000_000, true, 1], [$updated['credit_limit_minor'], $updated['route_extras'], $updated['lock_version']]);
        $this->refused(fn () => $this->companies()->update($this->property(), $this->companyManagerId, $acme['id'], ['name' => 'PT Acme', 'kind' => 'company'], true, 0, 'Stale'), 409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'company.updated')->where('aggregate_id', $acme['id'])->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'company.created')->count());
    }

    public function test_viewing_needs_the_view_or_manage_right(): void
    {
        $this->company();
        $this->refused(fn () => $this->companies()->overview($this->property(), $this->managerId), 403);
        self::assertCount(1, $this->companies()->overview($this->property(), $this->companyViewerId)['companies']);
        self::assertFalse($this->companies()->overview($this->property(), $this->companyViewerId)['may']['manage']);
        self::assertTrue($this->companies()->overview($this->property(), $this->companyManagerId)['may']['manage']);
        $this->refused(fn () => $this->companies()->accounts($this->property(), $this->managerId), 403);
    }

    public function test_a_reservation_is_billed_to_a_company_once_and_gets_its_own_folio(): void
    {
        $acme = $this->company();
        $in = $this->inHouse();

        $before = $this->companies()->forReservation($this->property(), $this->companyLinkerId, $in['reservation']);
        self::assertTrue($before['may_link']);
        self::assertSame(['ACME'], array_column($before['options'], 'code'));
        self::assertNull($before['company']);
        self::assertFalse($this->companies()->forReservation($this->property(), $this->companyViewerId, $in['reservation'])['may_link']);
        self::assertNull($this->companies()->forReservation($this->property(), $this->managerId, $in['reservation'])['company']);

        $this->refused(fn () => $this->companies()->link($this->property(), $this->companyViewerId, $in['reservation'], $acme['id']), 403);
        $this->refused(fn () => $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], str_repeat('0', 26)), 422);

        $linked = $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], $acme['id']);
        self::assertNotNull($linked['folio_id']);
        self::assertSame([['label' => 'Guest', 'window' => 1], ['label' => 'ACME', 'window' => 2]], array_map(static fn (array $f): array => ['label' => $f['label'], 'window' => $f['window']], $this->folios($in['reservation'])));
        $this->refused(fn () => $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], $acme['id']), 409);

        $after = $this->companies()->forReservation($this->property(), $this->companyLinkerId, $in['reservation']);
        self::assertSame(['ACME', $linked['folio_id'], false], [$after['company']['code'], $after['folio_id'], $after['may_link']]);
        self::assertSame('Send the invoice to finance@acme.test', $after['company']['billing_instruction']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'company.linked')->where('aggregate_id', $in['reservation'])->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.company.linked')->where('aggregate_id', $in['reservation'])->count());
        self::assertTrue(app(CompanyRouting::class)->isCompanyFolio($this->property(), $linked['folio_id']));
        self::assertFalse(app(CompanyRouting::class)->isCompanyFolio($this->property(), $this->folios($in['reservation'])[0]['id']));
    }

    public function test_an_inactive_company_and_a_finished_booking_cannot_be_billed(): void
    {
        $acme = $this->company();
        $off = $this->company('OFF');
        $this->companies()->update($this->property(), $this->companyManagerId, $off['id'], ['name' => 'PT Off', 'kind' => 'agent'], false, 0, 'Stopped');
        $reservation = $this->book('2026-10-05', '2026-10-06', 'confirmed');

        $this->refused(fn () => $this->companies()->link($this->property(), $this->companyLinkerId, $reservation->id, $off['id']), 422);
        self::assertSame([$acme['id']], array_column($this->companies()->forReservation($this->property(), $this->companyLinkerId, $reservation->id)['options'], 'id'));

        app(ReservationService::class)->cancel($this->property(), $this->managerId, $reservation->id, 'Guest cancelled', $reservation->lockVersion);
        $this->refused(fn () => $this->companies()->link($this->property(), $this->companyLinkerId, $reservation->id, $acme['id']), 409);
    }

    public function test_the_night_charge_goes_to_the_company_folio_and_the_guest_folio_stays_clear(): void
    {
        $acme = $this->company();
        $in = $this->inHouse();
        $linked = $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], $acme['id']);

        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString('company-audit-0001'));

        [$guest, $company] = $this->folios($in['reservation']);
        self::assertSame(0, $guest['balance']);
        self::assertGreaterThan(0, $company['balance']);
        self::assertSame($linked['folio_id'], $company['id']);
        self::assertSame(1, DB::table('folio_postings')->where('folio_id', $company['id'])->where('source', 'night_audit')->count());
        self::assertSame(0, DB::table('folio_postings')->where('folio_id', $guest['id'])->where('source', 'night_audit')->count());
    }

    public function test_extras_follow_the_company_only_when_the_profile_says_so(): void
    {
        app(ChargeSchemeService::class)->define($this->property(), $this->adminId, 'laundry', '2026-10-01', '10', '10', true, 'Regional regulation');
        $roomsOnly = $this->company('ROOMS');
        $all = $this->company('ALL', ['route_extras' => true]);
        $a = $this->inHouse(0);
        $b = $this->inHouse(1);
        $this->companies()->link($this->property(), $this->companyLinkerId, $a['reservation'], $roomsOnly['id']);
        $this->companies()->link($this->property(), $this->companyLinkerId, $b['reservation'], $all['id']);

        app(FolioService::class)->postGuestCharge($this->property(), $this->managerId, $a['reservation'], 'laundry', 'LAUNDRY', 'Laundry', 50_000_000, 'laundry', 'ldy:company-a');
        app(FolioService::class)->postGuestCharge($this->property(), $this->managerId, $b['reservation'], 'laundry', 'LAUNDRY', 'Laundry', 50_000_000, 'laundry', 'ldy:company-b');

        [$aGuest, $aCompany] = $this->folios($a['reservation']);
        [$bGuest, $bCompany] = $this->folios($b['reservation']);
        self::assertSame(0, $aCompany['balance']);
        self::assertGreaterThan(0, $aGuest['balance']);
        self::assertSame(0, $bGuest['balance']);
        self::assertGreaterThan(0, $bCompany['balance']);
    }

    public function test_the_company_folio_may_stay_open_with_a_balance_after_the_guest_leaves(): void
    {
        $acme = $this->company();
        $in = $this->inHouse();
        $linked = $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], $acme['id']);
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString('company-audit-0002'));
        $owed = $this->folios($in['reservation'])[1]['balance'];

        app(StayService::class)->checkOut($this->property(), $this->managerId, $in['stay'], 0);

        [$guest, $company] = array_map(fn (array $f): array => [...$f, 'closed' => DB::table('folios')->where('id', $f['id'])->value('status') === 'closed'], $this->folios($in['reservation']));
        self::assertSame([true, 0], [$guest['closed'], $guest['balance']]);
        self::assertSame([false, $owed], [$company['closed'], $company['balance']]);

        $accounts = $this->companies()->accounts($this->property(), $this->companyViewerId);
        self::assertSame($owed, $accounts['total_minor']);
        self::assertSame([$linked['folio_id']], array_column($accounts['companies'][0]['folios'], 'folio_id'));
        self::assertFalse($accounts['companies'][0]['over_limit']);

        app(FolioService::class)->pay($this->property(), $this->managerId, $company['id'], 'bank_transfer', $owed, 'INV-1', 'settlement');
        self::assertSame(0, $this->companies()->accounts($this->property(), $this->companyViewerId)['total_minor']);
    }

    public function test_a_guest_folio_with_a_balance_still_blocks_checkout(): void
    {
        $acme = $this->company();
        $in = $this->inHouse();
        $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], $acme['id']);
        app(FolioService::class)->charge($this->property(), $this->managerId, $this->folios($in['reservation'])[0]['id'], 'MINIBAR', 'Minibar', 10_000_000, false);

        $this->refused(fn () => app(StayService::class)->checkOut($this->property(), $this->managerId, $in['stay'], 0), 409);
    }

    public function test_going_over_the_credit_limit_only_warns(): void
    {
        $acme = $this->company('SMALL', ['credit_limit_minor' => 1]);
        $in = $this->inHouse();
        $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], $acme['id']);
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString('company-audit-0003'));

        $accounts = $this->companies()->accounts($this->property(), $this->companyViewerId);
        self::assertTrue($accounts['companies'][0]['over_limit']);
        self::assertGreaterThan(1, $accounts['companies'][0]['used_minor']);
        self::assertTrue($this->companies()->overview($this->property(), $this->companyViewerId)['companies'][0]['over_limit']);

        $alert = array_column(app(DashboardService::class)->snapshot($this->property(), $this->analystId, null, null, null)['alerts'], null, 'code')['company_over_limit'];
        self::assertSame([1, '/front-office/companies', ['SMALL']], [$alert['count'], $alert['href'], $alert['items']]);
    }

    public function test_the_links_cannot_be_rewritten(): void
    {
        $acme = $this->company();
        $in = $this->inHouse();
        $this->companies()->link($this->property(), $this->companyLinkerId, $in['reservation'], $acme['id']);

        foreach (['reservation_companies' => 'linked_at', 'company_folios' => 'created_at'] as $table => $column) {
            foreach ([fn () => DB::table($table)->update([$column => now()]), fn () => DB::table($table)->delete()] as $do) {
                try {
                    $do();
                    self::fail("{$table} must be append-only");
                } catch (QueryException $e) {
                    self::assertStringContainsString('cannot be', $e->getMessage());
                }
            }
        }
    }
}
