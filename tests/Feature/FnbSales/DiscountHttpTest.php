<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-006: a discount or a complimentary item needs a reason and the approval the policy's threshold asks for; the bill follows what is left. */
final class DiscountHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $host;

    private UserRecord $cashier;

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
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, FnbAccess::DISCOUNT_APPLY, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->host = $make([FnbAccess::POS_OPERATE]);
        $this->cashier = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, FnbAccess::DISCOUNT_APPLY]);
        $this->fakeGuests();
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
    }

    private function policy(string $subject, int $min = 0): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => $subject, 'band_min_amount_minor' => $min, 'steps' => [['permission' => 'fnb.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function approve(string $approvalId): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['fnb.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()));
    }

    /** A bill with NASI ×2 (9 000 000) and TEA ×1 (2 000 000), sent. @return array{0: string, 1: string, 2: string} the bill and its two lines */
    private function bill(): array
    {
        $bill = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t1'], 'covers' => 2], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 2], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 1, 'item_id' => $this->id['tea'], 'quantity' => 1], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 2], $this->key())->assertOk();

        return [$bill, (string) DB::table('fnb_bill_lines')->where('item_code', 'NASI')->value('id'), (string) DB::table('fnb_bill_lines')->where('item_code', 'TEA')->value('id')];
    }

    private function lock(string $bill): int
    {
        return (int) DB::table('fnb_bills')->where('id', $bill)->value('lock_version');
    }

    public function test_a_percentage_or_an_amount_comes_off_the_line_and_the_charges_follow_what_is_left(): void
    {
        [$bill, $nasi] = $this->bill();
        $url = "/fnb/bills/{$bill}/lines/{$nasi}/discount";

        // 10% of 9 000 000 is 900 000; with no policy no approval is asked.
        $v = $this->postJson($url, ['kind' => 'percent', 'value' => 1000, 'reason' => 'Regular guest', 'lock_version' => 3], $this->key())->assertOk();
        $v->assertJsonPath('bill.lines.0.line_total_minor', 8_100_000)->assertJsonPath('bill.lines.0.gross_minor', 9_000_000)->assertJsonPath('bill.lines.0.discount_minor', 900_000)->assertJsonPath('bill.lines.0.discount_kind', 'percent')
            ->assertJsonPath('totals.discount_minor', 900_000)->assertJsonPath('totals.subtotal_minor', 10_100_000)->assertJsonPath('totals.service_charge_minor', 1_010_000)->assertJsonPath('totals.tax_minor', 1_111_000)->assertJsonPath('totals.total_minor', 12_221_000);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_line.discounted')->count());
        self::assertSame('Regular guest', DB::table('audit_entries')->where('action', 'fnb_line.discounted')->value('reason'));

        // It is replaced by an amount, kept against the gross, never stacked.
        $this->postJson($url, ['kind' => 'amount', 'value' => 500_000, 'reason' => 'Changed', 'lock_version' => 4], $this->key())->assertOk()->assertJsonPath('bill.lines.0.line_total_minor', 8_500_000)->assertJsonPath('bill.lines.0.discount_kind', 'amount');

        // Taken off again, with a reason.
        $this->postJson("{$url}/remove", ['reason' => '', 'lock_version' => 5], $this->key())->assertStatus(422);
        $this->postJson("{$url}/remove", ['reason' => 'Mistake', 'lock_version' => 5], $this->key())->assertOk()->assertJsonPath('bill.lines.0.line_total_minor', 9_000_000)->assertJsonPath('bill.lines.0.discount_kind', null)->assertJsonPath('totals.discount_minor', 0);
        $this->postJson("{$url}/remove", ['reason' => 'Again', 'lock_version' => 6], $this->key())->assertStatus(409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_line.discount_removed')->count());
    }

    public function test_a_discount_is_more_than_nothing_and_less_than_the_line_and_needs_a_reason_and_the_privilege(): void
    {
        [$bill, $nasi] = $this->bill();
        $url = "/fnb/bills/{$bill}/lines/{$nasi}/discount";
        $this->postJson($url, ['kind' => 'percent', 'value' => 1000, 'reason' => '', 'lock_version' => 3], $this->key())->assertStatus(422);
        $this->postJson($url, ['kind' => 'percent', 'value' => 10_000, 'reason' => 'All', 'lock_version' => 3], $this->key())->assertStatus(422);
        $this->postJson($url, ['kind' => 'amount', 'value' => 9_000_000, 'reason' => 'All', 'lock_version' => 3], $this->key())->assertStatus(422);
        $this->postJson($url, ['kind' => 'amount', 'reason' => 'None', 'lock_version' => 3], $this->key())->assertStatus(422);
        $this->postJson($url, ['kind' => 'cash-back', 'value' => 1, 'reason' => 'x', 'lock_version' => 3], $this->key())->assertStatus(422);
        $this->postJson($url, ['kind' => 'amount', 'value' => 1000, 'reason' => 'Stale', 'lock_version' => 1], $this->key())->assertStatus(409);

        $this->actAs($this->host);
        $this->postJson($url, ['kind' => 'amount', 'value' => 1000, 'reason' => 'No right', 'lock_version' => 3], $this->key())->assertStatus(403);
        self::assertSame(0, DB::table('fnb_bill_lines')->where('discount_minor', '>', 0)->count());
    }

    public function test_a_discount_above_the_threshold_of_the_policy_waits_for_an_approval_that_is_for_that_discount_only(): void
    {
        $this->policy('fnb.discount', 1_000_000);
        [$bill, $nasi, $tea] = $this->bill();
        $url = "/fnb/bills/{$bill}/lines/{$nasi}/discount";

        // Below the threshold: the reason is enough.
        $this->postJson("/fnb/bills/{$bill}/lines/{$tea}/discount", ['kind' => 'amount', 'value' => 500_000, 'reason' => 'Small gesture', 'lock_version' => 3], $this->key())->assertOk();

        // Above it: refused until approved.
        $this->postJson($url, ['kind' => 'percent', 'value' => 2000, 'reason' => 'Complaint', 'lock_version' => 4], $this->key())->assertStatus(409)->assertJsonPath('error.conflict.reason', 'approval_required');
        $approval = (string) $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/discount-request", ['kind' => 'percent', 'value' => 2000, 'reason' => 'Complaint'], $this->key())->assertCreated()->assertJsonPath('approval.status', 'pending')->json('approval.id');
        $this->postJson($url, ['kind' => 'percent', 'value' => 2000, 'reason' => 'Complaint', 'lock_version' => 4, 'approval_id' => $approval], $this->key())->assertStatus(409);
        $this->approve($approval);
        $this->get("/fnb/bills/{$bill}")->assertInertia(fn ($page) => $page->where('view.approvals.0.subject_type', 'fnb.discount')->where('view.approvals.0.status', 'approved'));

        // The approval was for 20 percent: another value is not covered by it.
        $this->postJson($url, ['kind' => 'percent', 'value' => 3000, 'reason' => 'Complaint', 'lock_version' => 4, 'approval_id' => $approval], $this->key())->assertStatus(409);
        $this->postJson($url, ['kind' => 'percent', 'value' => 2000, 'reason' => 'Complaint', 'lock_version' => 4, 'approval_id' => $approval], $this->key())->assertOk()->assertJsonPath('bill.lines.0.discount_minor', 1_800_000)->assertJsonPath('totals.discount_minor', 2_300_000);
        self::assertContains($approval, DB::table('audit_entries')->where('action', 'fnb_line.discounted')->pluck('approval_reference')->all());
        // Used once.
        $this->postJson($url, ['kind' => 'percent', 'value' => 2000, 'reason' => 'Complaint', 'lock_version' => 5, 'approval_id' => $approval], $this->key())->assertStatus(409);
    }

    public function test_a_complimentary_item_is_refused_without_a_policy_and_costs_nothing_with_one(): void
    {
        [$bill, $nasi] = $this->bill();
        $url = "/fnb/bills/{$bill}/lines/{$nasi}/discount";
        $this->postJson($url, ['kind' => 'comp', 'reason' => 'Guest of the owner', 'lock_version' => 3], $this->key())->assertStatus(409);
        self::assertSame(9_000_000, (int) DB::table('fnb_bill_lines')->where('id', $nasi)->value('line_total_minor'));

        $this->policy('fnb.comp');
        $approval = (string) $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/discount-request", ['kind' => 'comp', 'reason' => 'Guest of the owner'], $this->key())->assertCreated()->json('approval.id');
        $this->approve($approval);
        $this->postJson($url, ['kind' => 'comp', 'reason' => 'Guest of the owner', 'lock_version' => 3, 'approval_id' => $approval], $this->key())->assertOk()
            ->assertJsonPath('bill.lines.0.line_total_minor', 0)->assertJsonPath('bill.lines.0.discount_kind', 'comp')->assertJsonPath('bill.lines.0.gross_minor', 9_000_000)->assertJsonPath('totals.subtotal_minor', 2_000_000);
        self::assertSame($approval, DB::table('audit_entries')->where('action', 'fnb_line.comped')->value('approval_reference'));
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'fnb.line.voided')->count(), 'the dish was cooked, so it is not voided');
    }

    public function test_the_bill_is_paid_for_what_is_left_and_a_discount_is_not_changed_after_a_payment(): void
    {
        [$bill, $nasi, $tea] = $this->bill();
        $total = (int) $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/discount", ['kind' => 'percent', 'value' => 1000, 'reason' => 'Regular guest', 'lock_version' => 3], $this->key())->assertOk()->json('totals.total_minor');
        self::assertGreaterThan(0, $total);

        $this->actAs($this->cashier);
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $paid = $this->postJson("/fnb/bills/{$bill}/payments", ['lock_version' => $this->lock($bill), 'method' => 'cash', 'amount_minor' => $total, 'tendered_minor' => $total], $this->key())->assertOk();
        $paid->assertJsonPath('bill.status', 'settled')->assertJsonPath('totals.discount_minor', 900_000);
        self::assertSame($total, (int) DB::table('fnb_bills')->where('id', $bill)->value('total_minor'));
        self::assertSame(9_000_000, (int) DB::table('fnb_bill_lines')->where('id', $nasi)->value('gross_minor'));

        // A partial payment on another bill stops the discount.
        $other = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t2'], 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$other}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 1], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$other}/send", ['lock_version' => 1], $this->key())->assertOk();
        $line = (string) DB::table('fnb_bill_lines')->where('bill_id', $other)->value('id');
        $this->postJson("/fnb/bills/{$other}/lines/{$line}/discount", ['kind' => 'amount', 'value' => 100_000, 'reason' => 'Before paying', 'lock_version' => 2], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$other}/payments", ['lock_version' => 3, 'method' => 'cash', 'amount_minor' => 1_000_000, 'tendered_minor' => 1_000_000], $this->key())->assertOk()->assertJsonPath('bill.status', 'open');
        $this->postJson("/fnb/bills/{$other}/lines/{$line}/discount", ['kind' => 'amount', 'value' => 200_000, 'reason' => 'After paying', 'lock_version' => $this->lock($other)], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$other}/lines/{$line}/discount/remove", ['reason' => 'After paying', 'lock_version' => $this->lock($other)], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$bill}/lines/{$tea}/discount", ['kind' => 'amount', 'value' => 100_000, 'reason' => 'Late', 'lock_version' => $this->lock($bill)], $this->key())->assertStatus(409);
    }

    public function test_a_voided_line_takes_no_discount(): void
    {
        [$bill, $nasi] = $this->bill();
        DB::table('fnb_bill_lines')->where('id', $nasi)->update(['status' => 'voided']);
        $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/discount", ['kind' => 'amount', 'value' => 1000, 'reason' => 'x', 'lock_version' => 3], $this->key())->assertStatus(409);
    }
}
