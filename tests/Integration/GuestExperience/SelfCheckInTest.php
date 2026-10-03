<?php

declare(strict_types=1);

namespace Tests\Integration\GuestExperience;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\GuestExperience\Application\GuestAccess;
use App\Modules\GuestExperience\Application\SelfCheckInQueueService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Policies\BookingPolicyService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-GST-001 to FR-GST-007: a guest pre-registers from a link or the lobby code, the receptionist verifies it, and only then is the guest checked in. */
final class SelfCheckInTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
        $this->signIn(self::PROPERTY, [GuestAccess::CHECKIN_MANAGE, GuestAccess::PRIVACY_MANAGE, GuestAccess::IDENTITY_VIEW, StayService::MANAGE_PERMISSION, StayService::VIEW_PERMISSION, FolioService::MANAGE_PERMISSION]);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    /** A link made by the receptionist for a reservation. @return array{0: string, 1: string} the reservation id and the token */
    private function link(string $arrival = '2026-10-01', string $departure = '2026-10-03', string $status = 'confirmed'): array
    {
        app(PropertyContext::class)->activate($this->property());
        $reservation = $this->book($arrival, $departure, $status);
        $made = $this->postJson('/guest/checkins/links', ['reservation_id' => $reservation->id])->assertCreated()->json();

        return [$reservation->id, $made['token']];
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return [
            'full_name' => 'Budi Santoso', 'nationality' => 'id', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'id_valid_until' => '', 'visa_number' => '', 'address' => 'Jl. Merdeka 1, Jakarta', 'phone' => '+62 812 3456', 'email' => 'budi@example.com',
            'adults' => '2', 'children' => '0', 'notice_version' => '0', 'agree' => '1', 'locale' => 'id', 'deposit_claimed' => '', 'deposit_reference' => '',
            'photo' => UploadedFile::fake()->createWithContent('ktp.png', (string) base64_decode(self::PNG, true)),
            'signature' => 'data:image/png;base64,'.self::PNG,
            ...$over,
        ];
    }

    private function send(string $token, array $over = []): TestResponse
    {
        return $this->post('/g/c/'.$token, $this->form($over), ['Accept' => 'application/json']);
    }

    private function viewOf(string $token): array
    {
        return $this->getJson('/g/c/'.$token, ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->json('props.view') ?? [];
    }

    public function test_the_link_opens_the_form_only_for_its_reservation_and_in_the_window(): void
    {
        [, $token] = $this->link();
        $this->get('/g/c/'.$token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $p) => $p->component('guest/pages/checkin')->where('view.state', 'form')->where('view.reservation.guest_name', 'Budi Santoso')->where('view.reservation.nights', 2)->where('view.notice.version', 0)->where('view.deposit.due_minor', 0)->has('view.id_types', 5));

        [, $early] = $this->link('2026-10-10', '2026-10-12');
        $this->get('/g/c/'.$early)->assertInertia(fn (Assert $p) => $p->where('view.state', 'too_early')->where('view.opens_on', '2026-10-08'));
        $this->send($early)->assertStatus(409);
        self::assertSame(0, DB::table('ge_checkins')->count());
    }

    public function test_unknown_withdrawn_and_expired_links_answer_alike_and_an_expired_link_is_never_used_again(): void
    {
        [$reservationId, $token] = $this->link();
        $unknown = str_repeat('A', 32);
        $this->get('/g/c/'.$unknown)->assertStatus(404)->assertInertia(fn (Assert $p) => $p->component('guest/pages/ended')->where('reason', 'link'));
        $this->getJson('/g/c/'.$unknown)->assertStatus(404);

        $made = DB::table('ge_checkin_links')->where('reservation_id', $reservationId)->first();
        $this->postJson("/guest/checkins/links/{$made->id}/revoke")->assertOk();
        $this->get('/g/c/'.$token)->assertStatus(404)->assertInertia(fn (Assert $p) => $p->where('reason', 'link'));
        $this->send($token)->assertStatus(404);

        $fresh = $this->postJson('/guest/checkins/links', ['reservation_id' => $reservationId])->assertCreated()->json('token');
        $this->get('/g/c/'.$fresh)->assertOk();
        $this->clock->advance('+4 days');
        $this->get('/g/c/'.$fresh)->assertStatus(404)->assertInertia(fn (Assert $p) => $p->where('reason', 'link'));
        $this->send($fresh)->assertStatus(404);
        self::assertSame(0, DB::table('ge_checkins')->count());
    }

    public function test_sending_the_form_needs_the_notice_and_a_consent_and_waits_for_a_receptionist_without_touching_the_room(): void
    {
        [$reservationId, $token] = $this->link();

        $this->send($token, ['agree' => ''])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['agree']]]);
        $this->send($token, ['notice_version' => '3'])->assertStatus(409);
        $this->send($token, ['full_name' => '', 'nationality' => 'Indonesia', 'adults' => '9'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['full_name', 'nationality', 'adults']]]);
        $this->send($token, ['photo' => UploadedFile::fake()->createWithContent('x.png', 'not an image')])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['photo']]]);
        $this->send($token, ['signature' => ''])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['signature']]]);
        self::assertSame(0, DB::table('ge_checkins')->count());

        $this->send($token, ['deposit_claimed' => '1'])->assertCreated()->assertJsonPath('view.state', 'waiting');
        $row = DB::table('ge_checkins')->first();
        self::assertSame(['submitted', $reservationId, 0, 'id', 2, 0], [$row->status, $row->reservation_id, (int) $row->notice_version, $row->consent_locale, (int) $row->adults, (int) $row->children]);
        self::assertNull($row->deposit_method, 'no deposit is due, so none is claimed');
        self::assertStringNotContainsString('3174010101900001', (string) $row->data, 'the details are sealed');
        self::assertSame(0, DB::table('stays')->count(), 'nothing is checked in until a person verifies');
        self::assertSame(2, DB::table('stored_files')->where('owner_type', 'self_checkin')->where('owner_id', $row->id)->count());
        $consent = DB::table('consent_records')->where('subject_id', $row->id)->first();
        self::assertSame(['self_checkin', 'guest_registration', '0', 1], [$consent->subject_type, $consent->purpose, $consent->notice_version, (int) $consent->granted]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'guest.self_checkin.submitted')->count());

        $this->send($token)->assertStatus(409);
        self::assertSame(1, DB::table('ge_checkins')->count());
        $this->get('/g/c/'.$token)->assertInertia(fn (Assert $p) => $p->where('view.state', 'waiting')->missing('view.room_number'));
    }

    public function test_the_receptionist_verifies_and_that_is_the_check_in_with_the_deposit_the_photo_and_the_card(): void
    {
        $owner = UserRecord::factory()->create();
        $this->grant($owner, self::PROPERTY, [BookingPolicyService::MANAGE_PERMISSION]);
        app(PropertyContext::class)->activate($this->property());
        app(BookingPolicyService::class)->define($this->property(), strtolower((string) $owner->getKey()), null, null, '2026-10-01', false, 'fixed', 50_000_000, 0, 0, 'none', 0, 'none', 0, 'Baseline');
        [$reservationId, $token] = $this->link();
        self::assertSame(50_000_000, (int) DB::table('reservations')->where('id', $reservationId)->value('deposit_required_minor'));
        $this->send($token, ['deposit_claimed' => '1', 'deposit_reference' => 'QR-778'])->assertCreated();
        $id = (string) DB::table('ge_checkins')->value('id');

        $this->getJson('/guest/checkins/'.$id)->assertOk()->assertJsonPath('checkin.data.full_name', 'Budi Santoso')->assertJsonPath('checkin.data.id_number', '3174010101900001')->assertJsonPath('checkin.data.nationality', 'ID')
            ->assertJsonPath('checkin.deposit.claimed_minor', 50_000_000)->assertJsonPath('checkin.deposit.reference', 'QR-778')->assertJsonPath('checkin.consent.version', 0)->assertJsonPath('checkin.has_photo', true);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'pii.accessed')->where('aggregate_id', $id)->count(), 'reading the details is recorded');
        $this->get("/guest/checkins/{$id}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/guest/checkins/{$id}/signature")->assertOk();

        $room = $this->roomIds[0];
        $this->postJson("/guest/checkins/{$id}/verify", ['lock_version' => 5, 'room_id' => $room])->assertStatus(409);
        self::assertSame(0, DB::table('stays')->count());

        $verify = $this->postJson("/guest/checkins/{$id}/verify", ['lock_version' => 0, 'room_id' => $room, 'key_note' => 'Key card is at the desk until 14:00', 'deposit_received_minor' => 50_000_000]);
        self::assertSame(200, $verify->getStatusCode(), json_encode($verify->json()));
        $verify
            ->assertJsonPath('checkin.status', 'verified')->assertJsonPath('checkin.data', null);

        $stay = DB::table('stays')->first();
        self::assertSame(['in_house', $room, $reservationId], [$stay->status, $stay->room_id, $stay->reservation_id]);
        self::assertNotNull($stay->id_photo_file_id, 'the identity photo went to the stay');
        self::assertSame(1, DB::table('registration_cards')->where('stay_id', $stay->id)->count(), 'the card carries the signature');
        $payment = DB::table('folio_postings')->where('entry_type', 'payment')->first();
        self::assertSame(['deposit', 'qris', -50_000_000, 'QR-778'], [$payment->payment_purpose, $payment->payment_method, (int) $payment->total_minor, $payment->payment_reference]);

        $done = DB::table('ge_checkins')->first();
        self::assertSame(['verified', null, $stay->id, '101'], [$done->status, $done->data, $done->stay_id, $done->room_number]);
        $this->get('/g/c/'.$token)->assertInertia(fn (Assert $p) => $p->where('view.state', 'verified')->where('view.room_number', '101')->where('view.key.note', 'Key card is at the desk until 14:00')->has('view.key.id')->has('view.key.en'));

        $this->postJson("/guest/checkins/{$id}/verify", ['lock_version' => 1, 'room_id' => $room])->assertStatus(409);
        $this->postJson("/guest/checkins/{$id}/reject", ['lock_version' => 1, 'reason' => 'Too late'])->assertStatus(409);
        self::assertSame(1, DB::table('folio_postings')->where('entry_type', 'payment')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'guest.self_checkin.verified')->count());
    }

    public function test_a_refused_submission_shows_the_reason_erases_the_details_and_the_receptionist_sends_a_new_link(): void
    {
        [$reservationId, $token] = $this->link();
        $this->send($token)->assertCreated();
        $id = (string) DB::table('ge_checkins')->value('id');

        $this->postJson("/guest/checkins/{$id}/reject", ['lock_version' => 0, 'reason' => ''])->assertStatus(422);
        $this->postJson("/guest/checkins/{$id}/reject", ['lock_version' => 0, 'reason' => 'The photo of the ID is not readable'])->assertOk()->assertJsonPath('checkin.status', 'rejected');
        $row = DB::table('ge_checkins')->first();
        self::assertSame(['rejected', null, 'The photo of the ID is not readable'], [$row->status, $row->data, $row->reject_reason]);
        self::assertSame(0, DB::table('stays')->count());
        $this->get('/g/c/'.$token)->assertInertia(fn (Assert $p) => $p->where('view.state', 'rejected')->where('view.reason', 'The photo of the ID is not readable'));
        $this->send($token)->assertStatus(409);

        $again = $this->postJson('/guest/checkins/links', ['reservation_id' => $reservationId])->assertCreated()->json('token');
        $this->get('/g/c/'.$token)->assertStatus(404);
        $this->get('/g/c/'.$again)->assertInertia(fn (Assert $p) => $p->where('view.state', 'form'));
        $this->send($again)->assertCreated();
        self::assertSame(2, DB::table('ge_checkins')->count());
        $this->postJson('/guest/checkins/links', ['reservation_id' => $reservationId])->assertStatus(409);
    }

    public function test_the_lobby_code_asks_for_the_number_and_the_name_with_a_few_tries_and_gives_a_short_link(): void
    {
        app(PropertyContext::class)->activate($this->property());
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $lobby = $this->postJson('/guest/checkins/lobby', [])->assertCreated()->json('token');
        self::assertSame($lobby, $this->postJson('/guest/checkins/lobby', [])->json('token'), 'the code in force is shown again');
        $this->get('/g/c/'.$lobby)->assertInertia(fn (Assert $p) => $p->where('view.state', 'lobby'));
        $this->post("/g/c/{$lobby}/", $this->form(), ['Accept' => 'application/json'])->assertStatus(404);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/g/c/{$lobby}/find", ['reservation_number' => $reservation->number, 'name' => 'Someone Else'])->assertStatus(422);
        }

        $this->postJson("/g/c/{$lobby}/find", ['reservation_number' => $reservation->number, 'name' => 'Budi Santoso'])->assertStatus(409);
        self::assertSame(5, DB::table('ge_checkin_attempts')->count());

        $this->clock->advance('+16 minutes');
        $found = $this->postJson("/g/c/{$lobby}/find", ['reservation_number' => strtolower($reservation->number), 'name' => 'budi'])->assertOk()->json('token');
        $this->get('/g/c/'.$found)->assertInertia(fn (Assert $p) => $p->where('view.state', 'form')->where('view.reservation.number', $reservation->number));
        self::assertSame(2, DB::table('ge_checkin_links')->count());
        self::assertSame(60, (int) ((strtotime((string) DB::table('ge_checkin_links')->where('kind', 'reservation')->value('expires_at')) - $this->clock->nowUtc()->getTimestamp()) / 60));
        $this->postJson("/g/c/{$found}/find", ['reservation_number' => $reservation->number, 'name' => 'Budi'])->assertStatus(404);

        $renewed = $this->postJson('/guest/checkins/lobby', ['renew' => true])->assertCreated()->json('token');
        self::assertNotSame($lobby, $renewed);
        $this->get('/g/c/'.$lobby)->assertStatus(404);
    }

    public function test_the_privacy_notice_is_versioned_and_a_consent_names_the_version_it_agreed_to(): void
    {
        [, $token] = $this->link();
        $this->postJson('/guest/checkins/notice', ['body_id' => 'x', 'body_en' => 'y', 'reason' => 'Initial'])->assertStatus(422);
        $this->postJson('/guest/checkins/notice', ['body_id' => str_repeat('Data Anda dilindungi. ', 4), 'body_en' => str_repeat('Your data is protected. ', 4), 'reason' => 'Initial notice'])->assertCreated()->assertJsonPath('notice.version', 1);

        $this->get('/g/c/'.$token)->assertInertia(fn (Assert $p) => $p->where('view.notice.version', 1));
        $this->send($token, ['notice_version' => '0'])->assertStatus(409);
        $this->send($token, ['notice_version' => '1'])->assertCreated();
        $id = (string) DB::table('ge_checkins')->value('id');
        self::assertSame('1', DB::table('consent_records')->where('subject_id', $id)->value('notice_version'));
        self::assertSame(1, (int) DB::table('ge_checkins')->value('notice_version'));
        self::assertSame(hash('sha256', trim(str_repeat('Data Anda dilindungi. ', 4))."\n".trim(str_repeat('Your data is protected. ', 4))), DB::table('ge_checkins')->value('notice_digest'));

        $this->expectException(QueryException::class);
        DB::table('ge_privacy_notices')->update(['body_en' => 'changed']);
    }

    public function test_only_people_with_the_rights_read_what_guests_sent(): void
    {
        [, $token] = $this->link();
        $this->send($token)->assertCreated();
        $id = (string) DB::table('ge_checkins')->value('id');
        app(PropertyContext::class)->activate($this->property());
        $queue = app(SelfCheckInQueueService::class);

        foreach ([$this->clerkId, $this->managerId] as $who) {
            try {
                $queue->detail($this->property(), $who, $id);
                self::fail('Expected a refusal');
            } catch (Refusal $e) {
                self::assertSame(403, $e->status());
            }
        }

        $this->post('/logout');
        $this->signIn(self::PROPERTY, [GuestAccess::CHECKIN_MANAGE]);
        $this->getJson('/guest/checkins/'.$id)->assertForbidden();
        $this->get("/guest/checkins/{$id}/photo")->assertForbidden();
        $this->get('/guest/checkins')->assertOk()->assertInertia(fn (Assert $p) => $p->where('queue.may_read_identity', false)->has('queue.waiting', 1));
        $this->post('/logout');
        $this->signIn(self::PROPERTY, []);
        $this->get('/guest/checkins')->assertForbidden();
        $this->postJson('/guest/checkins/links', ['reservation_id' => '01arz3ndektsv4rrffq69g5fzz'])->assertForbidden();
    }
}
