<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\HumanResource\Application\SopCompletionConsumer;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-020, -021: what the operational modules tell Human Resource about checklists, and the board of how people are doing. */
final class PerformanceHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $ani;

    private UserRecord $clerk;

    private int $keys = 0;

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
        $this->app->instance(Clock::class, new AdjustableClock('2026-10-05 00:12:00'));

        $this->createProperty(self::A, 'A');
        $this->manager = UserRecord::factory()->create();
        $this->grant($this->manager, self::A, [HrAccess::MANAGE, HrAccess::ROSTER, HrAccess::ATTENDANCE, HrAccess::PERFORMANCE, PropertySettingsService::MANAGE_PERMISSION]);
        $this->ani = UserRecord::factory()->create();
        $this->grant($this->ani, self::A, ['housekeeping.view']);
        $this->clerk = UserRecord::factory()->create();
        $this->grant($this->clerk, self::A, ['housekeeping.view']);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'pf-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function employee(string $name, ?string $user): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => 'housekeeping', 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', ...($user === null ? [] : ['user_id' => $user])], $this->key())->assertCreated()->json('id');
    }

    /** @param array<string, mixed> $data */
    private function tell(string $type, string $run, array $data, string $at = '2026-10-05T03:00:00Z'): void
    {
        $event = new OutboxEvent(PropertyId::fromString(self::A), $type, $run, 1, ['run_id' => $run, 'checklist' => 'Opening', 'frequency' => 'daily', 'period' => '2026-10-05', ...$data]);
        app(SopCompletionConsumer::class)->consume(new OutboxMessage((string) Str::ulid(), $event, new DateTimeImmutable($at), (string) Str::ulid()));
    }

    /** @return array<string, mixed> */
    private function board(string $query = ''): array
    {
        return $this->get('/hr/performance'.$query)->assertOk()->viewData('page')['props']['overview'];
    }

    public function test_items_ticked_in_every_area_are_credited_once_to_the_person_and_counted_per_area(): void
    {
        $ani = strtolower((string) $this->ani->getKey());
        $run = strtolower((string) Str::ulid());
        $item = fn (string $id, int $done): array => ['item_id' => $id, 'completed_by' => $ani, 'completed' => $done, 'total' => 4];

        $this->tell('frontoffice.sop.item_completed', $run, $item('i1', 1));
        $this->tell('frontoffice.sop.item_completed', $run, $item('i1', 1));
        $this->tell('frontoffice.sop.item_completed', $run, $item('i2', 2));
        $this->tell('frontoffice.sop.item_completed', $run, $item('i3', 3));
        $kitchen = strtolower((string) Str::ulid());
        $this->tell('kitchen.sop.item_completed', $kitchen, ['item_id' => 'k1', 'completed_by' => $ani, 'completed' => 4, 'total' => 4]);
        $this->tell('maintenance.duty.completed', strtolower((string) Str::ulid()), ['completed_by' => $ani, 'total' => 5]);

        self::assertSame(3, DB::table('hr_sop_credits')->where('source', 'front_office')->count(), 'a repeated delivery credits nothing twice');
        self::assertSame(3, (int) DB::table('hr_sop_runs')->where('source', 'front_office')->value('completed'));
        self::assertSame(75, (int) DB::table('hr_sop_runs')->where('source', 'front_office')->value('percent'));

        $this->employee('Ani', $ani);
        $this->employee('Budi', null);
        $overview = $this->board();
        $rows = array_column($overview['board'], null, 'linked');
        self::assertSame([3 + 1 + 5, ['front_office' => 3, 'kitchen' => 1, 'maintenance' => 5]], [$rows[true]['sop_items'], (array) $rows[true]['sop_by']]);
        self::assertSame(0, $rows[false]['sop_items'], 'a person without an account has nothing to count');

        $sources = array_column($overview['sources'], null, 'source');
        self::assertSame([1, 0, 4, 3, 75], [$sources['front_office']['runs'], $sources['front_office']['complete'], $sources['front_office']['items'], $sources['front_office']['done'], $sources['front_office']['percent']]);
        self::assertSame([1, 1, 100], [$sources['kitchen']['runs'], $sources['kitchen']['complete'], $sources['kitchen']['percent']]);
    }

    public function test_the_board_counts_the_complaints_a_person_owns_and_filters_by_department_and_period(): void
    {
        $ani = strtolower((string) $this->ani->getKey());
        $this->employee('Ani', $ani);
        $base = ['property_id' => self::A, 'kind' => 'complaint', 'channel' => 'in_person', 'summary' => 'Noise', 'owner_id' => $this->ani->getKey(), 'created_by' => $this->manager->getKey(), 'lock_version' => 0, 'created_at' => '2026-10-02 03:00:00', 'updated_at' => '2026-10-02 03:00:00'];
        foreach ([
            ['id' => strtolower((string) Str::ulid()), 'number' => 'FB-1', 'severity' => 'high', 'status' => 'resolved', 'resolution' => 'Moved the guest', 'resolved_at' => '2026-10-03 03:00:00', ...$base],
            ['id' => strtolower((string) Str::ulid()), 'number' => 'FB-2', 'severity' => 'low', 'status' => 'open', ...$base],
            ['id' => strtolower((string) Str::ulid()), 'number' => 'FB-3', 'severity' => 'low', 'status' => 'open', ...[...$base, 'created_at' => '2026-07-01 03:00:00']],
        ] as $feedback) {
            DB::table('guest_feedback')->insert($feedback);
        }

        $row = $this->board()['board'][0];
        self::assertSame(['total' => 2, 'serious' => 1, 'resolved' => 1], $row['complaints']);

        self::assertCount(0, $this->board('?department=laundry')['board']);
        self::assertSame(1, $this->board('?from=2026-07-01&to=2026-07-30')['board'][0]['complaints']['total']);
        $this->get('/hr/performance?department=nowhere')->assertStatus(422);
        $this->get('/hr/performance?from=2026-10-05&to=2026-10-01')->assertStatus(422);
        $this->get('/hr/performance?from=2026-01-01&to=2026-10-01')->assertStatus(422);
    }

    public function test_the_board_needs_the_performance_right(): void
    {
        $this->actAs($this->clerk);
        $this->get('/hr/performance')->assertForbidden();
    }
}
