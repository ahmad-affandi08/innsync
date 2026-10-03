<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\GuestDesk\GuestStayDesk;
use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-GST-015, -016, -019 on the front office side: the guest self-service takes a request or a complaint, follows it and reads the bill, as an account that can do nothing else. */
final class GuestStayDeskTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $stayId;

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
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $this->stayId = app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString('desk-checkin-00000001'),
        )['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function desk(): GuestStayDesk
    {
        return app(GuestStayDesk::class);
    }

    public function test_a_request_is_taken_once_for_a_key_routed_like_any_request_and_followed_by_its_status(): void
    {
        $r = $this->desk()->openRequest($this->property(), $this->stayId, 'housekeeping', 'Two extra towels', 'Bathroom', 'k1');
        self::assertSame('REQ-000001', $r['number']);
        self::assertSame($r, $this->desk()->openRequest($this->property(), $this->stayId, 'housekeeping', 'Two extra towels', 'Bathroom', 'k1'), 'the same key is the same request');
        self::assertSame(1, DB::table('guest_requests')->count());
        self::assertNotNull(DB::table('guest_requests')->value('hk_task_id'), 'it opened the housekeeping task as any request does');

        self::assertSame(['number' => 'REQ-000001', 'status' => 'open', 'resolution' => null], $this->desk()->statusOf($this->property(), $this->stayId, 'request', $r['id']));
        $request = app(GuestRequestService::class)->start($this->property(), $this->requestStaffId, $r['id'], 0);
        app(GuestRequestService::class)->complete($this->property(), $this->requestStaffId, $r['id'], (int) $request['lock_version'], 'Delivered');
        self::assertSame(['number' => 'REQ-000001', 'status' => 'done', 'resolution' => 'Delivered'], $this->desk()->statusOf($this->property(), $this->stayId, 'request', $r['id']));
        self::assertNull($this->desk()->statusOf($this->property(), '01arz3ndektsv4rrffq69g5fzz', 'request', $r['id']), 'another stay does not see it');

        $systemUser = DB::table('users')->where('email', 'like', 'guest-self-service.%')->first();
        self::assertSame([0, 'Guest self-service'], [(int) $systemUser->is_active, $systemUser->name]);
        self::assertSame(strtolower((string) $systemUser->id), DB::table('guest_requests')->value('created_by'));
    }

    public function test_a_complaint_is_taken_as_medium_for_the_staff_to_rerate_and_its_status_follows_the_feedback(): void
    {
        $c = $this->desk()->recordComplaint($this->property(), $this->stayId, 'The air conditioner is noisy', null, 'k2');
        self::assertSame('FDB-000001', $c['number']);
        self::assertSame($c, $this->desk()->recordComplaint($this->property(), $this->stayId, 'The air conditioner is noisy', null, 'k2'));
        $row = DB::table('guest_feedback')->first();
        self::assertSame(['complaint', 'medium', 'online', 'open'], [$row->kind, $row->severity, $row->channel, $row->status]);
        self::assertSame('open', $this->desk()->statusOf($this->property(), $this->stayId, 'complaint', $c['id'])['status']);
        self::assertNull($this->desk()->statusOf($this->property(), '01arz3ndektsv4rrffq69g5fzz', 'complaint', $c['id']));
    }

    public function test_the_bill_so_far_and_the_stay_are_read_and_the_account_can_do_nothing_else(): void
    {
        $bill = $this->desk()->runningBill($this->property(), $this->stayId);
        self::assertSame('IDR', $bill['currency']);
        self::assertSame($bill['total_minor'] - $bill['paid_minor'], $bill['balance_minor']);

        $stay = $this->desk()->stay($this->property(), $this->stayId);
        self::assertSame([true, '2026-10-03', 'Budi Santoso'], [$stay['in_house'], $stay['expected_departure'], $stay['guest_name']]);
        self::assertNull($this->desk()->stay($this->property(), '01arz3ndektsv4rrffq69g5fzz'));
        self::assertNull($this->desk()->runningBill($this->property(), '01arz3ndektsv4rrffq69g5fzz'));

        // It holds the rights to take requests and complaints and to read a folio, and nothing that changes money or the stay.
        $actor = DB::table('users')->where('email', 'like', 'guest-self-service.%')->value('id');
        $codes = DB::table('user_role_assignments as a')->join('role_permissions as g', 'g.role_id', '=', 'a.role_id')->join('permissions as p', 'p.id', '=', 'g.permission_id')->where('a.user_id', $actor)->pluck('p.code')->all();
        sort($codes);
        self::assertSame(['front-office.feedback.manage', 'front-office.feedback.view', 'front-office.folio.view', 'front-office.request.manage', 'front-office.request.view'], $codes);
    }

    public function test_a_guest_who_has_left_is_not_served(): void
    {
        DB::table('stays')->where('id', $this->stayId)->update(['status' => 'checked_out', 'checked_out_at' => now(), 'checked_out_by' => $this->managerId, 'checked_out_business_date' => '2026-10-02']);

        try {
            $this->desk()->recordComplaint($this->property(), $this->stayId, 'Late complaint', null, 'k3');
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }

        try {
            $this->desk()->openRequest($this->property(), $this->stayId, 'other', 'Something', null, 'k4');
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame(409, $e->status());
        }
    }
}
