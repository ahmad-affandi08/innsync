<?php

declare(strict_types=1);

namespace Tests\Integration\Laundry;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Laundry\Application\ClaimService;
use App\Modules\Laundry\Application\LaundryRequest;
use App\Modules\Laundry\Application\LaundryService;
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

/** FR-LDY-006: damage and loss claims on a guest's laundry, with a photo, decided by a Manager on Duty. */
final class ClaimTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    /** A one-pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var array<string, mixed> */
    private array $order;

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
        config(['files.disk' => 'local']);
        $shirt = app(LaundryService::class)->addPriceItem($this->property(), $this->laundryManagerId, 'SHIRT', 'Shirt', 2_500_000, 'Opening price list')['id'];
        $reservation = $this->book('2026-10-01', '2026-10-04', 'confirmed');
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('claim-checkin-0001'));
        $this->order = app(LaundryService::class)->intake($this->property(), $this->clerkId, new LaundryRequest('BAG-0001', $this->roomIds[0], false, '2026-10-02', '17:00', null, [['price_item_id' => $shirt, 'quantity' => 4, 'brand' => 'Zara']]), IdempotencyKey::fromString('claim-intake-00001'));
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function claims(): ClaimService
    {
        return app(ClaimService::class);
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

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    /** @return array<string, mixed> a damage claim for two of the four shirts, 150,000.00 claimed */
    private function record(?string $actor = null, int $claimed = 15_000_000, bool $withLine = true, bool $photo = true): array
    {
        return $this->claims()->record($this->property(), $actor ?? $this->claimClerkId, $this->order['id'], $withLine ? $this->order['lines'][0]['id'] : null, $withLine ? 2 : 1, 'damage', 'Scorch mark on two shirts', $claimed, $photo ? $this->png() : null, $photo ? 'scorch.png' : null);
    }

    public function test_a_claim_is_recorded_with_a_photo_by_someone_who_may(): void
    {
        $this->refused(fn () => $this->record($this->claimViewerId), 403);
        $this->refused(fn () => $this->record($this->claimDutyManagerId), 403);

        $claim = $this->record();
        self::assertSame(['CLM-000001', 'open', 'damage', 15_000_000, 2, 'Shirt', 'LDY-000001'], [$claim['number'], $claim['status'], $claim['kind'], $claim['claimed_minor'], $claim['pieces'], $claim['item_name'], $claim['order_number']]);
        self::assertNotNull($claim['photo_file_id']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'laundry_claim.recorded')->where('aggregate_id', $claim['id'])->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'laundry.claim.recorded')->where('aggregate_id', $claim['id'])->count());

        // A claim on the bag as a whole needs no item, and no photo.
        $bag = $this->record(null, 5_000_000, false, false);
        self::assertSame([null, null, 1], [$bag['line_id'], $bag['photo_file_id'], $bag['pieces']]);
    }

    public function test_the_claim_is_validated(): void
    {
        $line = $this->order['lines'][0]['id'];
        $call = fn (array $o): array => $this->claims()->record($this->property(), $this->claimClerkId, $o['order'] ?? $this->order['id'], $o['line'] ?? $line, $o['pieces'] ?? 1, $o['kind'] ?? 'loss', $o['description'] ?? 'One shirt lost', $o['claimed'] ?? 1_000_000, $o['photo'] ?? null, null);

        $this->refused(fn () => $call(['kind' => 'theft']), 422);
        $this->refused(fn () => $call(['description' => ' ']), 422);
        $this->refused(fn () => $call(['claimed' => 0]), 422);
        $this->refused(fn () => $call(['claimed' => ClaimService::MAX_CLAIM_MINOR + 1]), 422);
        $this->refused(fn () => $call(['pieces' => 5]), 422);
        $this->refused(fn () => $call(['pieces' => 0]), 422);
        $this->refused(fn () => $call(['line' => str_repeat('0', 26)]), 422);
        $this->refused(fn () => $call(['order' => str_repeat('0', 26)]), 404);
        $this->refused(fn () => $call(['photo' => 'not an image']), 422);
        self::assertSame(0, DB::table('laundry_claims')->count());

        $this->refused(fn () => $this->claims()->record($this->property(), $this->claimClerkId, $this->order['id'], null, 2, 'loss', 'The bag', 1_000_000, null, null), 422);
    }

    public function test_a_cancelled_order_cannot_be_claimed_on(): void
    {
        app(LaundryService::class)->cancel($this->property(), $this->laundryManagerId, $this->order['id'], 'Guest changed their mind', $this->order['lock_version']);

        $this->refused(fn () => $this->record(), 409);
    }

    public function test_a_duty_manager_approves_up_to_the_claim_and_the_cap(): void
    {
        $claim = $this->record();
        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimClerkId, $claim['id'], 5_000_000, null, 0), 403);
        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimDutyManagerId, $claim['id'], 0, null, 0), 422);
        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimDutyManagerId, $claim['id'], 15_000_001, null, 0), 422);
        // Two shirts at 25,000.00: ten times is 500,000.00, so a claim of that much is the most that could be approved.
        $big = $this->record(null, 60_000_000);
        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimDutyManagerId, $big['id'], 60_000_000, null, 0), 422);
        $ok = $this->claims()->approve($this->property(), $this->claimDutyManagerId, $big['id'], 50_000_000, 'Within ten times the price', 0);
        self::assertSame(['approved', 50_000_000, 1], [$ok['status'], $ok['approved_minor'], $ok['lock_version']]);

        $done = $this->claims()->approve($this->property(), $this->claimDutyManagerId, $claim['id'], 10_000_000, 'Partly the guest\'s own iron', 0);
        self::assertSame([10_000_000, 'Partly the guest\'s own iron'], [$done['approved_minor'], $done['decision_note']]);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'laundry_claim.approved')->count());
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'laundry.claim.approved')->count());
    }

    public function test_a_claim_is_decided_once_and_never_by_whoever_recorded_it(): void
    {
        $mine = $this->record($this->claimBothId);
        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimBothId, $mine['id'], 1_000_000, null, 0), 403);
        $this->refused(fn () => $this->claims()->reject($this->property(), $this->claimBothId, $mine['id'], 'Not mine to decide', 0), 403);
        self::assertSame('approved', $this->claims()->approve($this->property(), $this->claimDutyManagerId, $mine['id'], 1_000_000, null, 0)['status']);

        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimDutyManagerId, $mine['id'], 1_000_000, null, 1), 409);
        $this->refused(fn () => $this->claims()->reject($this->property(), $this->claimDutyManagerId, $mine['id'], 'Too late', 1), 409);

        $other = $this->record();
        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimDutyManagerId, $other['id'], 1_000_000, null, 7), 409);
        $this->refused(fn () => $this->claims()->reject($this->property(), $this->claimDutyManagerId, $other['id'], ' ', 0), 422);
        $rejected = $this->claims()->reject($this->property(), $this->claimDutyManagerId, $other['id'], 'The mark was on the shirt when it came in', 0);
        self::assertSame(['rejected', null], [$rejected['status'], $rejected['approved_minor']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'laundry_claim.rejected')->count());
    }

    public function test_the_cap_can_be_changed_or_switched_off_with_a_reason(): void
    {
        self::assertSame(10, $this->claims()->overview($this->property(), $this->claimDutyManagerId, null)['cap_multiple']);
        $this->refused(fn () => $this->claims()->saveCap($this->property(), $this->claimDutyManagerId, 5, null, 'Policy'), 403);
        $this->refused(fn () => $this->claims()->saveCap($this->property(), $this->laundryManagerId, 101, null, 'Policy'), 422);
        $this->refused(fn () => $this->claims()->saveCap($this->property(), $this->laundryManagerId, 5, null, ' '), 422);

        $set = $this->claims()->saveCap($this->property(), $this->laundryManagerId, 2, null, 'Hotel policy');
        self::assertSame([2, 0], [$set['cap_multiple'], $set['lock_version']]);
        $this->refused(fn () => $this->claims()->saveCap($this->property(), $this->laundryManagerId, 3, null, 'Stale'), 409);

        $claim = $this->record(null, 15_000_000);
        // Two shirts at 25,000.00 and a cap of twice the price: 100,000.00.
        $this->refused(fn () => $this->claims()->approve($this->property(), $this->claimDutyManagerId, $claim['id'], 15_000_000, null, 0), 422);
        $this->claims()->saveCap($this->property(), $this->laundryManagerId, 0, 0, 'No cap');
        self::assertSame('approved', $this->claims()->approve($this->property(), $this->claimDutyManagerId, $claim['id'], 15_000_000, null, 0)['status']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'laundry_claim.cap_set')->count());
    }

    public function test_the_photo_is_private_and_kept_for_a_year_after_the_decision(): void
    {
        $claim = $this->record();
        self::assertNotSame('', $this->claims()->photo($this->property(), $this->claimViewerId, $claim['id'])->contents);
        $this->refused(fn () => $this->claims()->photo($this->property(), $this->managerId, $claim['id']), 403);
        $this->refused(fn () => $this->claims()->photo($this->property(), $this->claimViewerId, str_repeat('0', 26)), 404);
        self::assertNull(DB::table('stored_files')->where('id', $claim['photo_file_id'])->value('expires_at'));

        $this->claims()->reject($this->property(), $this->claimDutyManagerId, $claim['id'], 'Not our fault', 0);
        self::assertSame('2027-10-01', substr((string) DB::table('stored_files')->where('id', $claim['photo_file_id'])->value('expires_at'), 0, 10));
        self::assertSame(0, DB::table('stored_files')->where('id', $claim['photo_file_id'])->whereNull('expires_at')->count());

        $bag = $this->record(null, 1_000_000, false, false);
        $this->refused(fn () => $this->claims()->photo($this->property(), $this->claimViewerId, $bag['id']), 404);
    }

    public function test_lists_follow_the_rights_and_show_who_may_decide(): void
    {
        $mine = $this->record($this->claimBothId);
        $this->record();
        $this->refused(fn () => $this->claims()->overview($this->property(), $this->managerId, null), 403);
        $this->refused(fn () => $this->claims()->overview($this->property(), $this->claimViewerId, 'closed'), 422);

        $view = $this->claims()->overview($this->property(), $this->claimBothId, 'open');
        self::assertSame([false, true], array_column(array_reverse($view['claims']), 'may_decide'));
        self::assertSame([true, true, false], [$view['may']['record'], $view['may']['approve'], $view['may']['settings']]);
        self::assertSame(['LDY-000001'], array_column($view['orders'], 'number'));
        self::assertSame(['Shirt'], array_column($view['orders'][0]['lines'], 'item_name'));

        $watch = $this->claims()->overview($this->property(), $this->claimViewerId, null);
        self::assertSame([], $watch['orders']);
        self::assertCount(2, $this->claims()->ofOrder($this->property(), $this->claimViewerId, $this->order['id']));
        self::assertSame([], $this->claims()->ofOrder($this->property(), $this->managerId, $this->order['id']));
        self::assertNotNull($mine['id']);
    }

    public function test_the_records_cannot_be_rewritten_or_removed(): void
    {
        $claim = $this->record();

        foreach (['claimed_minor' => 1, 'description' => 'changed', 'kind' => 'loss'] as $column => $value) {
            try {
                DB::table('laundry_claims')->where('id', $claim['id'])->update([$column => $value]);
                self::fail("{$column} must not change");
            } catch (QueryException $e) {
                self::assertStringContainsString('cannot be changed', $e->getMessage());
            }
        }

        $this->claims()->reject($this->property(), $this->claimDutyManagerId, $claim['id'], 'Not our fault', 0);

        try {
            DB::table('laundry_claims')->where('id', $claim['id'])->update(['decision_note' => 'edited']);
            self::fail('A decided claim must not change');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be changed', $e->getMessage());
        }

        try {
            DB::table('laundry_claims')->delete();
            self::fail('A claim must not be deleted');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }
}
