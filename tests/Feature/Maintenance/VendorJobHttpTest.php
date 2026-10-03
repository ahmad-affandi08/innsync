<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\SupplierService;
use App\Modules\Maintenance\Application\MaintenanceAccess;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-MTC-010, -015: work given to a vendor is quoted by suppliers, approved by its amount, scheduled and closed with the real cost and a photo. */
final class VendorJobHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $viewer;

    private UserRecord $tech;

    private UserRecord $reporter;

    /** @var array<string, string> */
    private array $sup = [];

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

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([MaintenanceAccess::MANAGE, MaintenanceAccess::VENDOR, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION]);
        $this->viewer = $make([MaintenanceAccess::MANAGE]);
        $this->tech = $make([MaintenanceAccess::PERFORM]);
        $this->reporter = $make([MaintenanceAccess::REPORT]);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->sup['cool'] = (string) $this->postJson('/inventory/suppliers', ['code' => 'COOL', 'name' => 'Cool Air Service', 'payment_terms_days' => 14])->assertCreated()->json('supplier.id');
        $this->sup['fix'] = (string) $this->postJson('/inventory/suppliers', ['code' => 'FIX', 'name' => 'Fix It Engineering', 'payment_terms_days' => 14])->assertCreated()->json('supplier.id');
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
        return ['Idempotency-Key' => 'vj-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('d.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    }

    private function order(): string
    {
        return (string) $this->postJson('/maintenance/work-orders', ['title' => 'Chiller down', 'category' => 'hvac', 'reporter_department' => 'fnb', 'priority' => 'high', 'area' => 'Plant room'])->assertCreated()->json('work_order.id');
    }

    /** @return array<string, mixed> */
    private function job(string $wo, int $status = 201): array
    {
        return $this->postJson('/maintenance/vendor-jobs', ['work_order_id' => $wo, 'scope' => 'Repair the compressor of the chiller'], $this->key())->assertStatus($status)->json() ?? [];
    }

    /** @return array<string, mixed> */
    private function quote(string $job, string $supplier, int $amount, int $status = 201, array $o = []): array
    {
        return $this->postJson("/maintenance/vendor-jobs/{$job}/quotes", ['supplier_id' => $supplier, 'amount_minor' => $amount, ...$o])->assertStatus($status)->json() ?? [];
    }

    private function policy(int $min = 0): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => 'maintenance.vendor-job', 'band_min_amount_minor' => $min, 'steps' => [['permission' => 'maintenance.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function decide(string $approvalId, bool $approve = true): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['maintenance.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $service = app(ApprovalService::class);
        $approve ? $service->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey())) : $service->reject(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()), 'Too expensive');
    }

    public function test_a_job_goes_from_quotations_to_a_photo_of_the_work_and_blocks_the_work_order_until_then(): void
    {
        $wo = $this->order();
        $job = $this->job($wo);
        self::assertSame('VJ-000001', $job['number']);
        self::assertSame('quoting', $job['status']);
        $id = $job['id'];

        $this->quote($id, $this->sup['cool'], 9_000_000, 201, ['valid_until' => '2026-10-31', 'note' => 'Parts included']);
        $shown = $this->quote($id, $this->sup['fix'], 7_500_000);
        self::assertSame([7_500_000, 9_000_000], array_column($shown['quotes'], 'amount_minor'));
        self::assertTrue($shown['quotes'][0]['lowest']);

        // The lowest quotation is chosen without a reason; with no policy the amount is approved at once.
        $lowest = $shown['quotes'][0]['id'];
        $chosen = $this->postJson("/maintenance/vendor-jobs/{$id}/choose", ['quote_id' => $lowest, 'lock_version' => 0])->assertOk()->json();
        self::assertSame('approved', $chosen['status']);
        self::assertSame('Fix It Engineering', $chosen['supplier']);
        self::assertSame(7_500_000, $chosen['agreed_minor']);

        // The work order is not finished or cancelled while the job is open.
        $this->postJson("/maintenance/work-orders/{$wo}/assign", ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 0])->assertOk();
        $this->actAs($this->tech);
        $this->postJson("/maintenance/work-orders/{$wo}/start", ['lock_version' => 1])->assertOk();
        $this->post("/maintenance/work-orders/{$wo}/complete", ['note' => 'Done', 'photo' => $this->png(), 'lock_version' => 2], ['Accept' => 'application/json'])->assertStatus(409);
        $this->actAs($this->manager);
        $this->postJson("/maintenance/work-orders/{$wo}/cancel", ['reason' => 'No', 'lock_version' => 2])->assertStatus(409);

        $scheduled = $this->postJson("/maintenance/vendor-jobs/{$id}/schedule", ['scheduled_on' => '2026-10-06', 'note' => 'Ask for Pak Budi', 'lock_version' => 1])->assertOk()->json();
        self::assertSame('scheduled', $scheduled['status']);
        self::assertSame('2026-10-06', $scheduled['scheduled_on']);
        $this->postJson("/maintenance/vendor-jobs/{$id}/schedule", ['scheduled_on' => '2026-10-07', 'lock_version' => 2])->assertOk()->assertJsonPath('scheduled_on', '2026-10-07');

        $done = $this->post("/maintenance/vendor-jobs/{$id}/complete", ['actual_minor' => 7_500_000, 'invoice_ref' => 'INV-88', 'note' => 'Compressor replaced', 'photo' => $this->png(), 'lock_version' => 3], ['Accept' => 'application/json'])->assertOk()->json();
        self::assertSame('done', $done['status']);
        self::assertFalse($done['over_quote']);
        self::assertTrue($done['has_proof']);
        self::assertSame('INV-88', $done['invoice_ref']);
        $this->get("/maintenance/vendor-jobs/{$id}/proof")->assertOk();
        $this->actAs($this->tech);
        $this->get("/maintenance/vendor-jobs/{$id}/proof")->assertStatus(403);

        // Now the work order can be finished.
        $this->post("/maintenance/work-orders/{$wo}/complete", ['note' => 'Done', 'photo' => $this->png(), 'lock_version' => 2], ['Accept' => 'application/json'])->assertOk();
        $this->actAs($this->manager);
        self::assertSame(5, DB::table('maintenance_work_events')->where('work_order_id', $wo)->where('kind', 'vendor_job')->count());
        foreach (['created', 'chosen', 'scheduled', 'done'] as $a) {
            self::assertGreaterThanOrEqual(1, DB::table('audit_entries')->where('action', "vendor_job.{$a}")->count());
        }

        $this->get('/maintenance/reports?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $p) => $p->where('report.vendor.total_minor', 7_500_000)->where('report.vendor.jobs', 1)->where('report.vendor.over_quote', 0)
            ->where('report.vendor.by_supplier.0.supplier', 'Fix It Engineering')->where('report.vendor.by_category.0.category', 'hvac'));
        $this->get('/maintenance/reports?from=2026-11-01&to=2026-11-30')->assertInertia(fn (Assert $p) => $p->where('report.vendor.total_minor', 0)->where('report.vendor.jobs', 0));
        $this->get('/maintenance/vendor-work')->assertOk()->assertInertia(fn (Assert $p) => $p->component('maintenance/pages/vendor-work')->has('overview.jobs', 1)->where('overview.may.act', true));
    }

    public function test_a_quotation_that_is_not_the_lowest_needs_a_reason_and_a_cost_above_the_quotation_is_flagged(): void
    {
        $id = $this->job($this->order())['id'];
        $this->quote($id, $this->sup['cool'], 9_000_000);
        $shown = $this->quote($id, $this->sup['fix'], 7_500_000);
        $dearer = $shown['quotes'][1]['id'];

        $this->postJson("/maintenance/vendor-jobs/{$id}/choose", ['quote_id' => $dearer, 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/maintenance/vendor-jobs/{$id}/choose", ['quote_id' => '01arz3ndektsv4rrffq69g5fc9', 'reason' => 'x', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/maintenance/vendor-jobs/{$id}/choose", ['quote_id' => $dearer, 'reason' => 'They know the machine', 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/maintenance/vendor-jobs/{$id}/choose", ['quote_id' => $dearer, 'reason' => 'They know the machine', 'lock_version' => 0])->assertOk()->assertJsonPath('choice_reason', 'They know the machine')->assertJsonPath('agreed_minor', 9_000_000);

        $done = $this->post("/maintenance/vendor-jobs/{$id}/complete", ['actual_minor' => 9_800_000, 'note' => 'Extra part was needed', 'photo' => $this->png(), 'lock_version' => 1], ['Accept' => 'application/json'])->assertOk()->json();
        self::assertTrue($done['over_quote']);
        $this->get('/maintenance/reports?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $p) => $p->where('report.vendor.total_minor', 9_800_000)->where('report.vendor.over_quote', 1));
    }

    public function test_an_amount_over_the_policy_band_waits_for_the_chain_and_is_released_by_the_person_who_chose(): void
    {
        $this->policy(5_000_000);
        $id = $this->job($this->order())['id'];
        $quote = $this->quote($id, $this->sup['fix'], 7_500_000)['quotes'][0]['id'];
        $waiting = $this->postJson("/maintenance/vendor-jobs/{$id}/choose", ['quote_id' => $quote, 'lock_version' => 0])->assertOk()->json();
        self::assertSame('pending_approval', $waiting['status']);
        self::assertNotNull($waiting['approval']);

        $this->postJson("/maintenance/vendor-jobs/{$id}/release")->assertOk()->assertJsonPath('status', 'pending_approval');
        $this->postJson("/maintenance/vendor-jobs/{$id}/schedule", ['scheduled_on' => '2026-10-06', 'lock_version' => 1])->assertStatus(409);
        $this->decide($waiting['approval']['id']);
        $this->postJson("/maintenance/vendor-jobs/{$id}/release")->assertOk()->assertJsonPath('status', 'approved');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'vendor_job.approved')->count());

        // A rejected amount closes the job.
        $other = $this->job($this->order())['id'];
        $q2 = $this->quote($other, $this->sup['cool'], 8_000_000)['quotes'][0]['id'];
        $second = $this->postJson("/maintenance/vendor-jobs/{$other}/choose", ['quote_id' => $q2, 'lock_version' => 0])->assertOk()->json();
        $this->decide($second['approval']['id'], false);
        $this->postJson("/maintenance/vendor-jobs/{$other}/release")->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("/maintenance/vendor-jobs/{$other}/cancel", ['reason' => 'Too late', 'lock_version' => 2])->assertStatus(409);

        // A smaller amount is under the band and needs nobody.
        $small = $this->job($this->order())['id'];
        $q3 = $this->quote($small, $this->sup['cool'], 1_000_000)['quotes'][0]['id'];
        $this->postJson("/maintenance/vendor-jobs/{$small}/choose", ['quote_id' => $q3, 'lock_version' => 0])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_the_rules_of_who_may_and_when(): void
    {
        $wo = $this->order();
        $id = $this->job($wo)['id'];

        $this->actAs($this->tech);
        $this->postJson('/maintenance/vendor-jobs', ['work_order_id' => $wo, 'scope' => 'x'], $this->key())->assertStatus(403);
        $this->getJson("/maintenance/vendor-jobs/{$id}")->assertStatus(403);
        $this->get('/maintenance/vendor-work')->assertStatus(403);
        $this->actAs($this->reporter);
        $this->get('/maintenance/vendor-work')->assertStatus(403);
        $this->actAs($this->viewer);
        $this->get('/maintenance/vendor-work')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.jobs', 1)->where('overview.may.act', false)->has('overview.suppliers', 0));
        $this->getJson("/maintenance/vendor-jobs/{$id}")->assertOk()->assertJsonPath('may.quote', false);
        $this->postJson("/maintenance/vendor-jobs/{$id}/quotes", ['supplier_id' => $this->sup['cool'], 'amount_minor' => 100])->assertStatus(403);
        $this->postJson("/maintenance/vendor-jobs/{$id}/cancel", ['reason' => 'x', 'lock_version' => 0])->assertStatus(403);

        $this->actAs($this->manager);
        $this->job('01arz3ndektsv4rrffq69g5fc9', 422);
        $this->postJson('/maintenance/vendor-jobs', ['work_order_id' => $wo, 'scope' => ''], $this->key())->assertStatus(422);
        $this->quote($id, '01arz3ndektsv4rrffq69g5fc9', 100, 422);
        $this->quote($id, $this->sup['cool'], 0, 422);
        $this->quote($id, $this->sup['cool'], 100, 422, ['valid_until' => '2026-10-01']);
        $this->quote($id, $this->sup['cool'], 5_000_000);
        $this->quote($id, $this->sup['cool'], 4_000_000, 409);
        $this->postJson("/maintenance/vendor-jobs/{$id}/schedule", ['scheduled_on' => '2026-10-06', 'lock_version' => 0])->assertStatus(409);
        $this->post("/maintenance/vendor-jobs/{$id}/complete", ['actual_minor' => 1, 'note' => 'x', 'photo' => $this->png(), 'lock_version' => 0], ['Accept' => 'application/json'])->assertStatus(409);
        $this->postJson("/maintenance/vendor-jobs/{$id}/release")->assertStatus(409);

        $quote = (string) DB::table('maintenance_vendor_quotes')->value('id');
        $this->postJson("/maintenance/vendor-jobs/{$id}/choose", ['quote_id' => $quote, 'lock_version' => 0])->assertOk();
        $this->quote($id, $this->sup['fix'], 100, 409);
        $this->postJson("/maintenance/vendor-jobs/{$id}/schedule", ['scheduled_on' => '2026-10-02', 'lock_version' => 1])->assertStatus(422);
        $this->postJson("/maintenance/vendor-jobs/{$id}/schedule", ['scheduled_on' => '2026-10-04', 'lock_version' => 9])->assertStatus(409);
        $this->post("/maintenance/vendor-jobs/{$id}/complete", ['actual_minor' => 1, 'note' => 'Done', 'lock_version' => 1], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/maintenance/vendor-jobs/{$id}/complete", ['actual_minor' => 1, 'note' => '', 'photo' => $this->png(), 'lock_version' => 1], ['Accept' => 'application/json'])->assertStatus(422);
        self::assertSame('approved', DB::table('maintenance_vendor_jobs')->value('status'));

        $this->postJson("/maintenance/vendor-jobs/{$id}/cancel", ['reason' => '', 'lock_version' => 1])->assertStatus(422);
        $this->postJson("/maintenance/vendor-jobs/{$id}/cancel", ['reason' => 'The vendor withdrew', 'lock_version' => 1])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/maintenance/vendor-jobs/{$id}/cancel", ['reason' => 'Again', 'lock_version' => 2])->assertStatus(409);

        // A cancelled job no longer blocks the work order, and a closed work order takes no job.
        $this->postJson("/maintenance/work-orders/{$wo}/cancel", ['reason' => 'Handled otherwise', 'lock_version' => 0])->assertOk();
        $this->job($wo, 409);

        // A quotation cannot be changed or deleted.
        $this->expectException(QueryException::class);
        DB::table('maintenance_vendor_quotes')->update(['amount_minor' => 1]);
    }
}
