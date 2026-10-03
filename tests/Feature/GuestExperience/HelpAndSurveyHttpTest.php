<?php

declare(strict_types=1);

namespace Tests\Feature\GuestExperience;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\FrontOffice\Application\GuestDesk\GuestStayDesk;
use App\Modules\GuestExperience\Application\GuestAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-GST-015, -016, -019: a guest who proved the stay asks for something, reports a problem, follows both, sees the bill so far and answers the survey near the departure. */
final class HelpAndSurveyHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const STAY = '01arz3ndektsv4rrffq69g5fc2';

    private string $roomToken = '';

    /** @var array<string, array<string, mixed>> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $complaints = [];

    public string $departure = '2026-10-04';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::A, 'A');
        $owner = UserRecord::factory()->create();
        $this->grant($owner, self::A, [FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, GuestAccess::QR_MANAGE, GuestAccess::ORDER_MANAGE]);
        $this->fakeGuests();
        $test = $this;
        $this->app->instance(GuestStayDesk::class, new class($test) implements GuestStayDesk
        {
            public function __construct(private HelpAndSurveyHttpTest $t) {}

            public function openRequest(PropertyId $property, string $stayId, string $category, string $title, ?string $detail, string $clientKey): array
            {
                $n = count($this->t->requests) + 1;
                $this->t->requests['r'.$n] = ['kind' => 'request', 'number' => sprintf('REQ-%06d', $n), 'status' => 'open', 'category' => $category, 'title' => $title, 'stay' => $stayId];

                return ['id' => 'r'.$n, 'number' => sprintf('REQ-%06d', $n)];
            }

            public function recordComplaint(PropertyId $property, string $stayId, string $summary, ?string $detail, string $clientKey): array
            {
                $n = count($this->t->complaints) + 1;
                $this->t->complaints[] = ['id' => 'c'.$n, 'summary' => $summary, 'stay' => $stayId];
                $this->t->requests['c'.$n] = ['kind' => 'complaint', 'number' => sprintf('FDB-%06d', $n), 'status' => 'open', 'stay' => $stayId];

                return ['id' => 'c'.$n, 'number' => sprintf('FDB-%06d', $n)];
            }

            public function statusOf(PropertyId $property, string $stayId, string $kind, string $id): ?array
            {
                $r = $this->t->requests[$id] ?? null;

                return $r === null || $r['stay'] !== $stayId ? null : ['number' => $r['number'], 'status' => $r['status'], 'resolution' => $r['status'] === 'done' ? 'Towels delivered' : null];
            }

            public function runningBill(PropertyId $property, string $stayId): ?array
            {
                return $stayId !== '01arz3ndektsv4rrffq69g5fc2' ? null : [
                    'currency' => 'IDR', 'outlets' => [['outlet' => 'rooms', 'lines' => [['date' => '2026-10-02', 'description' => 'Room night', 'total_minor' => 60_000_000]], 'total_minor' => 60_000_000], ['outlet' => 'other', 'lines' => [['date' => '2026-10-03', 'description' => 'Dinner', 'total_minor' => 5_000_000]], 'total_minor' => 5_000_000]],
                    'payments' => [['date' => '2026-10-02', 'method' => 'card', 'amount_minor' => 20_000_000]], 'total_minor' => 65_000_000, 'paid_minor' => 20_000_000, 'balance_minor' => 45_000_000,
                ];
            }

            public function stay(PropertyId $property, string $stayId): ?array
            {
                return $stayId !== '01arz3ndektsv4rrffq69g5fc2' ? null : ['in_house' => true, 'expected_departure' => $this->t->departure, 'reservation_id' => '01arz3ndektsv4rrffq69g5fc3', 'room_id' => '01arz3ndektsv4rrffq69g5fc1', 'guest_name' => 'Budi Santoso'];
            }
        });
        $this->actAs($owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/guest/qr')->assertCreated();
        foreach ($this->get('/guest/qr/print')->assertOk()->viewData('page')['props']['codes'] as $c) {
            if ($c['label'] === 'Room 101') {
                $this->roomToken = $c['token'];
            }
        }

        $this->post('/logout');
        $this->flushSession();
    }

    private function scan(): string
    {
        $response = $this->get('/g/'.$this->roomToken);
        $response->assertRedirect('/g/menu');

        return (string) $response->getCookie('ge_session')->getValue();
    }

    private function proved(): string
    {
        $cookie = $this->scan();
        $this->guestPost($cookie, '/g/verify', ['room_number' => '101', 'surname' => 'Santoso'])->assertOk();

        return $cookie;
    }

    /** @param array<string, mixed> $data */
    private function guestPost(string $cookie, string $url, array $data): TestResponse
    {
        return $this->withCredentials()->withCookie('ge_session', $cookie)->postJson($url, $data);
    }

    private function guestPage(string $cookie, string $url): TestResponse
    {
        return $this->withCookie('ge_session', $cookie)->get($url);
    }

    public function test_help_needs_the_stay_proved_and_a_request_goes_to_the_department_and_is_followed_by_its_number(): void
    {
        $cookie = $this->scan();
        $this->guestPage($cookie, '/g/help')->assertInertia(fn (Assert $p) => $p->component('guest/pages/help')->where('view.verified', false)->where('view.requests', []));
        $this->guestPost($cookie, '/g/request', ['client_key' => 'req-key-0000000001', 'category' => 'housekeeping', 'title' => 'Two extra towels', 'detail' => null])->assertStatus(403);
        self::assertSame([], $this->requests);

        $this->guestPost($cookie, '/g/verify', ['room_number' => '101', 'surname' => 'Santoso'])->assertOk();
        $this->guestPost($cookie, '/g/request', ['client_key' => 'req-key-0000000001', 'category' => 'housekeeping', 'title' => 'Two extra towels', 'detail' => 'Bathroom'])->assertCreated()->assertJsonPath('requests.0.number', 'REQ-000001')->assertJsonPath('requests.0.status', 'open')->assertJsonPath('requests.0.title', 'Two extra towels');
        $this->guestPost($cookie, '/g/request', ['client_key' => 'req-key-0000000001', 'category' => 'housekeeping', 'title' => 'Two extra towels', 'detail' => 'Bathroom'])->assertCreated();
        self::assertSame([1, 1], [count($this->requests), DB::table('ge_requests')->count()], 'the same key is the same request');
        self::assertSame(['housekeeping', 'Two extra towels'], [$this->requests['r1']['category'], $this->requests['r1']['title']]);

        // The department finishes it and the guest sees it done, with what was done.
        $this->requests['r1']['status'] = 'done';
        $this->guestPage($cookie, '/g/help')->assertInertia(fn (Assert $p) => $p->where('view.verified', true)->where('view.requests.0.status', 'done')->where('view.requests.0.resolution', 'Towels delivered'));

        $this->guestPost($cookie, '/g/complaint', ['client_key' => 'cmp-key-0000000001', 'summary' => 'The air conditioner is noisy', 'detail' => null])->assertCreated()->assertJsonPath('requests.0.kind', 'complaint')->assertJsonPath('requests.0.number', 'FDB-000001');
        self::assertSame('The air conditioner is noisy', $this->complaints[0]['summary']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'guest_help.sent')->count());
    }

    public function test_a_request_is_checked_and_only_so_many_are_taken_an_hour_and_a_guest_sees_only_their_own(): void
    {
        $cookie = $this->proved();
        $bad = fn (array $over, int $status) => $this->guestPost($cookie, '/g/request', [...['client_key' => 'req-key-0000000002', 'category' => 'housekeeping', 'title' => 'Towels', 'detail' => null], ...$over])->assertStatus($status);
        $bad(['title' => ''], 422);
        $bad(['category' => 'spa'], 422);
        $bad(['client_key' => 'x'], 422);
        $bad(['title' => str_repeat('x', 121)], 422);
        self::assertSame([], $this->requests);

        config(['guest.requests_per_hour' => 2]);
        foreach ([3, 4] as $n) {
            $this->guestPost($cookie, '/g/request', ['client_key' => 'req-key-000000000'.$n, 'category' => 'other', 'title' => 'Something '.$n, 'detail' => null])->assertCreated();
        }

        $this->guestPost($cookie, '/g/request', ['client_key' => 'req-key-0000000005', 'category' => 'other', 'title' => 'One more', 'detail' => null])->assertStatus(409);

        // Another stay's requests never show: the status of a reference of another stay is unknown.
        $this->requests['r1']['stay'] = 'another-stay';
        $this->guestPage($cookie, '/g/help')->assertInertia(fn (Assert $p) => $p->has('view.requests', 1)->where('view.requests.0.title', 'Something 4'));
    }

    public function test_the_bill_so_far_is_shown_to_a_guest_who_proved_the_stay_only(): void
    {
        $cookie = $this->scan();
        $this->guestPage($cookie, '/g/bill')->assertInertia(fn (Assert $p) => $p->component('guest/pages/bill')->where('view.verified', false)->where('view.bill', null));
        $this->guestPost($cookie, '/g/verify', ['room_number' => '101', 'surname' => 'Santoso'])->assertOk();
        $this->guestPage($cookie, '/g/bill')->assertInertia(fn (Assert $p) => $p->where('view.verified', true)->where('view.bill.total_minor', 65_000_000)->where('view.bill.paid_minor', 20_000_000)->where('view.bill.balance_minor', 45_000_000)->where('view.bill.outlets.0.outlet', 'rooms')->where('view.departure', '2026-10-04'));
    }

    public function test_the_survey_opens_near_the_departure_is_answered_once_and_a_low_rating_opens_a_complaint(): void
    {
        $cookie = $this->proved();
        $this->departure = '2026-10-09';
        $this->guestPage($cookie, '/g/survey')->assertInertia(fn (Assert $p) => $p->component('guest/pages/survey')->where('view.open', false)->where('view.answered', false)->where('view.departure', '2026-10-09'));
        $this->guestPost($cookie, '/g/survey', ['overall' => 5])->assertStatus(409);

        $this->departure = '2026-10-04';
        $this->guestPage($cookie, '/g/survey')->assertInertia(fn (Assert $p) => $p->where('view.open', true));
        $this->guestPost($cookie, '/g/survey', ['overall' => 6])->assertStatus(422);
        $this->guestPost($cookie, '/g/survey', ['overall' => 4, 'room_rating' => 0])->assertStatus(422);
        $this->guestPost($cookie, '/g/survey', ['overall' => 4, 'room_rating' => 5, 'service_rating' => 4, 'food_rating' => null, 'value_rating' => 3, 'comment' => 'Lovely stay'])->assertCreated()->assertJsonPath('answered', true);
        $this->guestPost($cookie, '/g/survey', ['overall' => 2])->assertStatus(409);
        self::assertSame([4, 5, null, 'Lovely stay'], [(int) DB::table('ge_surveys')->value('overall'), (int) DB::table('ge_surveys')->value('room_rating'), DB::table('ge_surveys')->value('food_rating'), DB::table('ge_surveys')->value('comment')]);
        self::assertSame([], $this->complaints, 'a good rating opens nothing');

        try {
            DB::table('ge_surveys')->update(['overall' => 1]);
            self::fail('A survey was changed.');
        } catch (QueryException $e) {
            self::assertStringContainsString('a survey cannot be changed', $e->getMessage());
        }
    }

    public function test_a_low_overall_rating_opens_a_complaint_for_the_staff_to_follow_up(): void
    {
        $cookie = $this->proved();
        $this->guestPost($cookie, '/g/survey', ['overall' => 2, 'comment' => 'Noisy at night'])->assertCreated();
        self::assertCount(1, $this->complaints);
        self::assertSame('Low rating in the guest survey (2/5)', $this->complaints[0]['summary']);
        self::assertSame('c1', DB::table('ge_surveys')->value('complaint_id'));

        $this->guestPost($cookie, '/g/survey', ['overall' => 1])->assertStatus(409);
        self::assertCount(1, $this->complaints);
    }

    public function test_staff_see_the_answers_and_their_averages(): void
    {
        $cookie = $this->proved();
        $this->guestPost($cookie, '/g/survey', ['overall' => 4, 'room_rating' => 5, 'service_rating' => 3, 'comment' => 'Fine'])->assertCreated();
        $this->post('/logout');
        $this->flushSession();

        $reader = UserRecord::factory()->create();
        $this->grant($reader, self::A, [GuestAccess::ORDER_MANAGE]);
        $this->actAs($reader);
        $this->get('/guest/surveys')->assertOk()->assertInertia(fn (Assert $p) => $p->component('guest/pages/surveys')->where('overview.count', 1)->where('overview.averages.overall', 400)->where('overview.averages.room_rating', 500)->where('overview.averages.food_rating', null)->where('overview.surveys.0.comment', 'Fine'));

        $nobody = UserRecord::factory()->create();
        $this->grant($nobody, self::A, []);
        $this->actAs($nobody);
        $this->get('/guest/surveys')->assertStatus(403);
    }
}
