<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-023, FR-HR-024: reprimands, warning letters and awards with the letter and the day until which they hold, and the notices and policies the staff must read and confirm. */
final class ConductAndAnnouncementHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $ani;

    private UserRecord $budi;

    private UserRecord $other;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $emp = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity_access.login_rate_limit_per_minute' => 1000]);
        $this->app->instance(Clock::class, new AdjustableClock('2026-10-05 03:00:00'));

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->owner = $make([HrAccess::MANAGE, HrAccess::CONDUCT, HrAccess::ANNOUNCE, PropertySettingsService::MANAGE_PERMISSION]);
        $this->ani = $make(['housekeeping.view']);
        $this->budi = $make(['housekeeping.view']);
        $this->other = $make(['housekeeping.view']);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->emp['ani'] = $this->employee('Ani', 'housekeeping', $this->ani);
        $this->emp['budi'] = $this->employee('Budi', 'kitchen', $this->budi);
        $this->emp['candra'] = $this->employee('Candra', 'housekeeping', null);
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function employee(string $name, string $department, ?UserRecord $user): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => $department, 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', ...($user === null ? [] : ['user_id' => (string) $user->getKey()])], $this->key())->assertCreated()->json('id');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'ca-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function letter(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('letter.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    }

    /** @param array<string, mixed> $o */
    private function record(string $who, string $kind, array $o = [], int $status = 201): ?array
    {
        $r = $this->post('/hr/conduct', ['employee_id' => $this->emp[$who], 'kind' => $kind, 'issued_on' => '2026-10-01', 'reason' => 'Late three times', ...$o], [...$this->key(), 'Accept' => 'application/json']);
        self::assertSame($status, $r->getStatusCode(), json_encode($o).' '.$r->getContent());

        return $status === 201 ? $r->json() : null;
    }

    public function test_a_warning_holds_for_its_days_a_letter_is_private_and_a_mistake_is_revoked_not_deleted(): void
    {
        // The usual days: 3 months for a reprimand, 6 for a warning letter; an award never lapses.
        self::assertSame(['2027-01-01', 'active'], [($verbal = $this->record('ani', 'verbal'))['valid_until'], $verbal['state']]);
        $sp1 = $this->record('ani', 'sp1', ['letter' => $this->letter()]);
        self::assertSame(['2027-04-01', true, 'active'], [$sp1['valid_until'], $sp1['has_letter'], $sp1['state']]);
        $award = $this->record('budi', 'award', ['valid_until' => '2027-01-01', 'reason' => 'Employee of the month']);
        self::assertSame([null, 'award'], [$award['valid_until'], $award['state']]);

        // A warning letter holds at most six months; the day cannot be in the future or long past, and the person must exist.
        $this->record('ani', 'sp2', ['valid_until' => '2027-05-01'], 422);
        $this->record('ani', 'sp2', ['issued_on' => '2026-10-06'], 422);
        $this->record('ani', 'sp2', ['issued_on' => '2026-07-01'], 422);
        $this->record('ani', 'sp2', ['reason' => ' '], 422);
        $this->record('ani', 'strike', [], 422);
        $this->record('ani', 'sp2', ['letter' => UploadedFile::fake()->createWithContent('x.exe', 'MZ this is not a letter')], 422);
        $this->postJson('/hr/conduct', ['employee_id' => '01arz3ndektsv4rrffq69g5fc9', 'kind' => 'verbal', 'issued_on' => '2026-10-01', 'reason' => 'x'], $this->key())->assertStatus(404);

        // The person sees their own records and their own letter, nobody else's.
        $this->actAs($this->ani);
        $mine = $this->get('/hr/conduct')->assertOk()->viewData('page')['props']['overview'];
        self::assertSame([false, ['sp1', 'verbal']], [$mine['may']['manage'], array_column($mine['mine'], 'kind') === ['sp1', 'verbal'] || array_column($mine['mine'], 'kind') === ['verbal', 'sp1'] ? ['sp1', 'verbal'] : []]);
        self::assertNull($mine['records']);
        $this->get("/hr/conduct/{$sp1['id']}/letter")->assertOk();
        $this->postJson('/hr/conduct', ['employee_id' => $this->emp['ani'], 'kind' => 'verbal', 'issued_on' => '2026-10-01', 'reason' => 'x'], $this->key())->assertForbidden();
        $this->postJson("/hr/conduct/{$sp1['id']}/revoke", ['reason' => 'x', 'lock_version' => 0])->assertForbidden();
        $this->actAs($this->budi);
        $this->get("/hr/conduct/{$sp1['id']}/letter")->assertForbidden();
        $this->actAs($this->other);
        self::assertSame(['linked' => false], ['linked' => $this->get('/hr/conduct')->assertOk()->viewData('page')['props']['overview']['linked']]);

        // A mistake is revoked with a reason and stays on file.
        $this->actAs($this->owner);
        self::assertCount(3, $this->get('/hr/conduct')->viewData('page')['props']['overview']['records']);
        self::assertCount(2, $this->get('/hr/conduct?employee='.$this->emp['ani'])->viewData('page')['props']['overview']['records']);
        $this->postJson("/hr/conduct/{$sp1['id']}/revoke", ['reason' => ' ', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/hr/conduct/{$sp1['id']}/revoke", ['reason' => 'Wrong person', 'lock_version' => 7])->assertStatus(409);
        $this->postJson("/hr/conduct/{$sp1['id']}/revoke", ['reason' => 'Wrong person', 'lock_version' => 0])->assertOk()->assertJsonPath('state', 'revoked');
        $this->postJson("/hr/conduct/{$sp1['id']}/revoke", ['reason' => 'Again', 'lock_version' => 1])->assertStatus(409);
        self::assertSame(3, DB::table('hr_conduct_records')->count());

        try {
            DB::table('hr_conduct_records')->delete();
            self::fail('a conduct record cannot be deleted');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(1, DB::table('audit_entries')->where('action', 'conduct.letter_opened')->count());
    }

    public function test_a_warning_lapses_after_its_day_and_the_letter_keeps_its_retention_when_the_person_leaves(): void
    {
        $this->record('ani', 'verbal', ['issued_on' => '2026-08-10', 'valid_until' => '2026-09-10']);
        $this->record('ani', 'sp1', ['issued_on' => '2026-10-01', 'letter' => $this->letter()]);
        $states = array_column($this->get('/hr/conduct?employee='.$this->emp['ani'])->viewData('page')['props']['overview']['records'], 'state', 'kind');
        self::assertSame(['expired', 'active'], [$states['verbal'], $states['sp1']]);

        $fileId = (string) DB::table('hr_conduct_records')->whereNotNull('file_id')->value('file_id');
        self::assertNull(DB::table('stored_files')->where('id', $fileId)->value('expires_at'));
        $lock = (int) DB::table('hr_employees')->where('id', $this->emp['ani'])->value('lock_version');
        $this->postJson("/hr/employees/{$this->emp['ani']}/offboard", ['kind' => 'resigned', 'offboarded_on' => '2026-10-05', 'reason' => 'Moved away', 'lock_version' => $lock, 'items' => []])->assertOk();
        self::assertNotNull(DB::table('stored_files')->where('id', $fileId)->value('expires_at'), 'the retention of the letter starts when the person leaves');

        $this->record('ani', 'verbal', [], 409);
    }

    public function test_a_policy_goes_to_its_audience_must_be_confirmed_once_and_the_board_shows_who_has_not(): void
    {
        $this->postJson('/hr/announcements', ['kind' => 'policy', 'title' => 'Fire drill', 'body' => 'Meet at the car park.', 'audience' => 'all', 'requires_ack' => true], $this->key())->assertCreated();
        $kitchen = $this->post('/hr/announcements', ['kind' => 'announcement', 'title' => 'New knives', 'body' => 'Collect them.', 'audience' => 'kitchen', 'requires_ack' => false, 'expires_on' => '2026-10-10', 'document' => $this->letter()], [...$this->key(), 'Accept' => 'application/json'])->assertCreated();
        $this->postJson('/hr/announcements', ['kind' => 'memo', 'title' => 'x', 'body' => 'x', 'audience' => 'all', 'requires_ack' => false], $this->key())->assertStatus(422);
        $this->postJson('/hr/announcements', ['kind' => 'policy', 'title' => 'x', 'body' => 'x', 'audience' => 'moon', 'requires_ack' => false], $this->key())->assertStatus(422);
        $this->postJson('/hr/announcements', ['kind' => 'policy', 'title' => ' ', 'body' => 'x', 'audience' => 'all', 'requires_ack' => false], $this->key())->assertStatus(422);
        $this->postJson('/hr/announcements', ['kind' => 'policy', 'title' => 'x', 'body' => 'x', 'audience' => 'all', 'requires_ack' => false, 'expires_on' => '2026-10-04'], $this->key())->assertStatus(422);
        $ids = array_column($kitchen->json('board'), 'id', 'title');

        // Housekeeping sees the policy only; the kitchen sees both.
        $this->actAs($this->ani);
        $feed = $this->get('/hr/announcements')->assertOk()->viewData('page')['props']['overview']['feed'];
        self::assertSame(['Fire drill'], array_column($feed, 'title'));
        self::assertNull($this->get('/hr/announcements')->viewData('page')['props']['overview']['board']);
        $this->postJson("/hr/announcements/{$ids['New knives']}/read")->assertNotFound();
        $this->get("/hr/announcements/{$ids['New knives']}/document")->assertNotFound();
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/read")->assertOk();
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/read")->assertOk();
        self::assertSame(1, DB::table('hr_announcement_reads')->where('employee_id', $this->emp['ani'])->count());
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/acknowledge")->assertOk();
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/acknowledge")->assertOk();
        self::assertSame(1, DB::table('audit_entries')->where('action', 'announcement.acknowledged')->count(), 'confirmed once');

        $this->actAs($this->budi);
        self::assertSame(['New knives', 'Fire drill'], array_column($this->get('/hr/announcements')->viewData('page')['props']['overview']['feed'], 'title'));
        $this->postJson("/hr/announcements/{$ids['New knives']}/acknowledge")->assertStatus(409);
        $this->get("/hr/announcements/{$ids['New knives']}/document")->assertOk();
        self::assertSame(['unread' => 2, 'to_confirm' => 1], $this->get('/hr/me')->viewData('page')['props']['portal']['announcements']);

        // An account without an employee record has no board to read.
        $this->actAs($this->other);
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/read")->assertForbidden();

        // The owner sees how many read and confirmed, and who has not (Budi and Candra of the everyone-notice).
        $this->actAs($this->owner);
        $board = array_column($this->get('/hr/announcements')->viewData('page')['props']['overview']['board'], null, 'title');
        self::assertSame([3, 1, 1], [$board['Fire drill']['audience_size'], $board['Fire drill']['read'], $board['Fire drill']['acknowledged']]);
        self::assertSame(['Budi', 'Candra'], array_column($board['Fire drill']['pending'], 'name'));
        self::assertSame([1, 0, 0], [$board['New knives']['audience_size'], $board['New knives']['acknowledged'], count($board['New knives']['pending'])]);

        // A published notice is withdrawn with a reason, never edited; its reads stay.
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/withdraw", ['reason' => ' ', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/withdraw", ['reason' => 'New date', 'lock_version' => 0])->assertOk();
        $this->postJson("/hr/announcements/{$ids['Fire drill']}/withdraw", ['reason' => 'Again', 'lock_version' => 1])->assertStatus(409);
        self::assertSame(1, DB::table('hr_announcement_reads')->where('announcement_id', $ids['Fire drill'])->whereNotNull('acknowledged_at')->count());
        $this->actAs($this->ani);
        self::assertSame([], $this->get('/hr/announcements')->viewData('page')['props']['overview']['feed']);

        foreach ([fn () => DB::table('hr_announcement_reads')->update(['acknowledged_at' => '2026-10-06 00:00:00']), fn () => DB::table('hr_announcement_reads')->delete(), fn () => DB::table('hr_announcements')->delete()] as $change) {
            try {
                $change();
                self::fail('this cannot be changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        // A notice that expired is no longer shown.
        $this->actAs($this->budi);
        DB::table('hr_announcements')->where('id', $ids['New knives'])->update(['expires_on' => '2026-10-04']);
        self::assertSame([], $this->get('/hr/announcements')->viewData('page')['props']['overview']['feed']);
    }

    public function test_publishing_and_recording_need_their_own_rights(): void
    {
        $this->actAs($this->ani);
        $this->postJson('/hr/announcements', ['kind' => 'policy', 'title' => 'x', 'body' => 'x', 'audience' => 'all', 'requires_ack' => false], $this->key())->assertForbidden();
        $this->postJson('/hr/announcements/01arz3ndektsv4rrffq69g5fc9/withdraw', ['reason' => 'x', 'lock_version' => 0])->assertForbidden();
        self::assertSame(0, DB::table('hr_announcements')->count());
    }
}
