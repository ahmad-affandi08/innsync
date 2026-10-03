<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-015: imprest funds, vouchers with proof, the custodian's settlement, the second person's decision and the replenishment. */
final class PettyCashHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $manager2;

    private UserRecord $custodian;

    private UserRecord $other;

    private UserRecord $dual;

    private UserRecord $viewer;

    private string $account;

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

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([FinanceAccess::PETTY_MANAGE, FinanceAccess::ACCOUNT_MANAGE, PropertySettingsService::MANAGE_PERMISSION]);
        $this->manager2 = $make([FinanceAccess::PETTY_MANAGE]);
        $this->custodian = $make([FinanceAccess::PETTY_OPERATE]);
        $this->other = $make([FinanceAccess::PETTY_OPERATE]);
        $this->dual = $make([FinanceAccess::PETTY_OPERATE, FinanceAccess::PETTY_MANAGE]);
        $this->viewer = $make([FinanceAccess::PETTY_VIEW]);
        config(['files.disk' => 'local']);

        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->account = (string) $this->postJson('/finance/accounts', ['code' => 'OFFICE', 'name' => 'Office supplies', 'department' => 'general', 'category' => 'supplies'])->assertCreated()->json('account.id');
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
        return ['Idempotency-Key' => 'pc-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    /** A fund of IDR 2,000,000 with a voucher limit of IDR 500,000, held by the custodian. @return array<string, mixed> */
    private function fund(?UserRecord $custodian = null, string $code = 'FO'): array
    {
        $this->actAs($this->manager);

        return $this->postJson('/finance/petty', ['code' => $code, 'name' => 'Front office petty cash', 'custodian_id' => (string) ($custodian ?? $this->custodian)->getKey(), 'imprest_minor' => 200_000_000, 'max_voucher_minor' => 50_000_000])->assertCreated()->json('fund');
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function spend(string $fund, UserRecord $by, int $amount, array $extra = []): array
    {
        $this->actAs($by);

        return $this->postJson("/finance/petty/{$fund}/vouchers", ['payee' => 'Toko Maju', 'description' => 'Printer paper', 'expense_account_id' => $this->account, 'amount_minor' => $amount, 'receipt_ref' => 'N-1', ...$extra], $this->key())->assertCreated()->json('fund');
    }

    private function proof(string $voucher): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $this->post("/finance/petty/vouchers/{$voucher}/proofs", ['proof' => UploadedFile::fake()->createWithContent('nota.pdf', $pdf)], ['Accept' => 'application/json'])->assertCreated();
    }

    private function voucherId(string $number): string
    {
        return (string) DB::table('fin_petty_vouchers')->where('number', $number)->value('id');
    }

    public function test_a_fund_is_made_for_a_custodian_and_opens_with_its_amount(): void
    {
        $this->actAs($this->custodian);
        $body = ['code' => 'FO', 'name' => 'Front office', 'custodian_id' => (string) $this->custodian->getKey(), 'imprest_minor' => 200_000_000];
        $this->postJson('/finance/petty', $body)->assertForbidden();

        $this->actAs($this->manager);
        $this->postJson('/finance/petty', [...$body, 'code' => 'bad code!'])->assertStatus(422);
        $this->postJson('/finance/petty', [...$body, 'custodian_id' => (string) $this->manager2->getKey()])->assertStatus(422);
        $this->postJson('/finance/petty', [...$body, 'custodian_id' => str_repeat('0', 26)])->assertStatus(422);
        $this->postJson('/finance/petty', [...$body, 'max_voucher_minor' => 200_000_001])->assertStatus(422);
        self::assertSame(0, DB::table('fin_petty_funds')->count());

        $fund = $this->postJson('/finance/petty', [...$body, 'code' => 'fo'])->assertCreated()->json('fund');
        self::assertSame(['FO', 200_000_000, 200_000_000, 0, true], [$fund['code'], $fund['imprest_minor'], $fund['balance_minor'], $fund['unsettled_count'], $fund['active']]);
        self::assertSame(['opening', 200_000_000], [$fund['entries'][0]['kind'], $fund['entries'][0]['signed_minor']]);
        $this->postJson('/finance/petty', $body)->assertStatus(409);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'petty_fund.created')->first());

        foreach ([fn () => DB::table('fin_petty_funds')->update(['imprest_minor' => 1]), fn () => DB::table('fin_petty_funds')->update(['code' => 'X']), fn () => DB::table('fin_petty_funds')->delete(), fn () => DB::table('fin_petty_entries')->update(['signed_minor' => 1]), fn () => DB::table('fin_petty_entries')->delete()] as $change) {
            try {
                $change();
                self::fail('A fund or its ledger was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_funds_are_seen_by_viewers_and_managers_and_by_their_custodian_only(): void
    {
        $this->fund();
        $this->fund($this->other, 'KT');

        $this->actAs($this->manager);
        $this->get('/finance/petty')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/petty-funds')->has('overview.funds', 2)->where('overview.may.manage', true)->has('overview.custodians', 3));
        $this->actAs($this->viewer);
        $this->get('/finance/petty')->assertOk()->assertInertia(fn (Assert $page) => $page->has('overview.funds', 2)->where('overview.may.manage', false)->where('overview.custodians', []));
        $this->actAs($this->custodian);
        $this->get('/finance/petty')->assertOk()->assertInertia(fn (Assert $page) => $page->has('overview.funds', 1)->where('overview.funds.0.code', 'FO'));
        $kt = (string) DB::table('fin_petty_funds')->where('code', 'KT')->value('id');
        $this->get("/finance/petty/{$kt}")->assertForbidden();

        $this->actAs($this->manager);
        $nobody = UserRecord::factory()->create();
        $this->grant($nobody, self::A, []);
        $this->actAs($nobody);
        $this->get('/finance/petty')->assertForbidden();
    }

    public function test_only_the_custodian_records_vouchers_within_the_limit_and_the_balance(): void
    {
        $fund = $this->fund();
        $this->actAs($this->other);
        $this->postJson("/finance/petty/{$fund['id']}/vouchers", ['payee' => 'X', 'description' => 'Y', 'expense_account_id' => $this->account, 'amount_minor' => 1_000], $this->key())->assertForbidden();

        $this->actAs($this->custodian);
        $url = "/finance/petty/{$fund['id']}/vouchers";
        $post = fn (array $body) => $this->postJson($url, ['payee' => 'Toko Maju', 'description' => 'Printer paper', 'expense_account_id' => $this->account, 'amount_minor' => 1_000_000, ...$body], $this->key());
        $post(['amount_minor' => 0])->assertStatus(422);
        $post(['amount_minor' => 50_000_001])->assertStatus(422);
        $post(['payee' => '  '])->assertStatus(422);
        $post(['voucher_date' => '2026-10-04'])->assertStatus(422);
        $post(['expense_account_id' => str_repeat('0', 26)])->assertStatus(422);
        self::assertSame(0, DB::table('fin_petty_vouchers')->count());

        $headers = $this->key();
        $body = ['payee' => 'Toko Maju', 'description' => 'Printer paper', 'expense_account_id' => $this->account, 'amount_minor' => 30_000_000, 'receipt_ref' => 'N-77', 'voucher_date' => '2026-10-02'];
        $one = $this->postJson($url, $body, $headers)->assertCreated()->json('fund');
        $this->postJson($url, $body, $headers);
        self::assertSame(1, DB::table('fin_petty_vouchers')->count(), 'a retry with the same key records once');
        self::assertSame([170_000_000, 30_000_000, 1, 'PCV-000001', 'open'], [$one['balance_minor'], $one['unsettled_minor'], $one['unsettled_count'], $one['vouchers'][0]['number'], $one['vouchers'][0]['state']]);
        self::assertSame(['expense', -30_000_000], [$one['entries'][0]['kind'], $one['entries'][0]['signed_minor']]);

        $this->spend($fund['id'], $this->custodian, 50_000_000);
        $this->spend($fund['id'], $this->custodian, 50_000_000);
        $this->spend($fund['id'], $this->custodian, 40_000_000);
        $this->actAs($this->custodian);
        $post(['amount_minor' => 40_000_001])->assertStatus(409);
        self::assertSame(30_000_000 + 50_000_000 + 50_000_000 + 40_000_000, (int) DB::table('fin_petty_vouchers')->sum('amount_minor'));

        try {
            DB::table('fin_petty_entries')->insert(['id' => '01arz3ndektsv4rrffq69g5fc1', 'property_id' => self::A, 'fund_id' => $fund['id'], 'seq' => 99, 'kind' => 'expense', 'signed_minor' => -30_000_001, 'business_date' => '2026-10-03', 'created_by' => $this->custodian->getKey(), 'created_at' => now()]);
            self::fail('The database let the fund go below zero.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        foreach ([fn () => DB::table('fin_petty_vouchers')->update(['amount_minor' => 1]), fn () => DB::table('fin_petty_vouchers')->delete()] as $change) {
            try {
                $change();
                self::fail('A voucher was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_proofs_are_added_by_the_custodian_and_seen_by_those_who_may_see_the_fund(): void
    {
        $fund = $this->fund();
        $this->spend($fund['id'], $this->custodian, 1_000_000);
        $voucher = $this->voucherId('PCV-000001');
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $this->actAs($this->other);
        $this->post("/finance/petty/vouchers/{$voucher}/proofs", ['proof' => UploadedFile::fake()->createWithContent('nota.pdf', $pdf)], ['Accept' => 'application/json'])->assertForbidden();

        $this->actAs($this->custodian);
        $this->post("/finance/petty/vouchers/{$voucher}/proofs", ['proof' => UploadedFile::fake()->create('x.exe', 1, 'application/octet-stream')], ['Accept' => 'application/json'])->assertStatus(422);
        $with = $this->post("/finance/petty/vouchers/{$voucher}/proofs", ['proof' => UploadedFile::fake()->createWithContent('nota.pdf', $pdf)], ['Accept' => 'application/json'])->assertCreated()->json('fund');
        self::assertSame(1, $with['vouchers'][0]['proof_count']);
        $proof = (string) DB::table('fin_petty_proofs')->value('id');
        $this->get("/finance/petty/vouchers/{$voucher}/proofs/{$proof}")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actAs($this->viewer);
        $this->get("/finance/petty/vouchers/{$voucher}/proofs/{$proof}")->assertOk();
        $this->actAs($this->other);
        $this->get("/finance/petty/vouchers/{$voucher}/proofs/{$proof}")->assertForbidden();

        $this->actAs($this->custodian);
        for ($i = 0; $i < 4; $i++) {
            $this->post("/finance/petty/vouchers/{$voucher}/proofs", ['proof' => UploadedFile::fake()->createWithContent('nota.pdf', $pdf)], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->post("/finance/petty/vouchers/{$voucher}/proofs", ['proof' => UploadedFile::fake()->createWithContent('nota.pdf', $pdf)], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_a_voucher_is_voided_by_someone_else_and_the_money_goes_back(): void
    {
        $fund = $this->fund($this->dual);
        $this->spend($fund['id'], $this->dual, 4_000_000);
        $voucher = $this->voucherId('PCV-000001');
        $url = "/finance/petty/vouchers/{$voucher}/void";

        $this->postJson($url, ['reason' => 'Wrong amount'])->assertForbidden();
        $this->actAs($this->custodian);
        $this->postJson($url, ['reason' => 'Wrong amount'])->assertForbidden();

        $this->actAs($this->manager);
        $this->postJson($url, ['reason' => ' '])->assertStatus(422);
        $back = $this->postJson($url, ['reason' => 'Wrong amount'])->assertOk()->json('fund');
        self::assertSame([200_000_000, 'voided', 0, 'void'], [$back['balance_minor'], $back['vouchers'][0]['state'], $back['unsettled_count'], $back['entries'][0]['kind']]);
        $this->postJson($url, ['reason' => 'Again'])->assertStatus(409);

        $this->spend($fund['id'], $this->dual, 1_000_000);
        $this->actAs($this->dual);
        $this->postJson('/finance/petty/vouchers/'.$this->voucherId('PCV-000002').'/void', ['reason' => 'My own'])->assertForbidden();
    }

    public function test_a_settlement_needs_proofs_or_reasons_and_blocks_new_vouchers_until_it_is_decided(): void
    {
        $fund = $this->fund();
        $this->spend($fund['id'], $this->custodian, 30_000_000, ['receipt_ref' => null]);
        $this->spend($fund['id'], $this->custodian, 20_000_000, ['no_receipt_reason' => 'Parking, no receipt given']);
        $url = "/finance/petty/{$fund['id']}/settlements";

        $this->actAs($this->other);
        $this->postJson($url, ['counted_minor' => 150_000_000], $this->key())->assertForbidden();

        $this->actAs($this->custodian);
        $this->postJson($url, ['counted_minor' => 150_000_000], $this->key())->assertStatus(409);
        $this->proof($this->voucherId('PCV-000001'));
        $this->postJson($url, ['counted_minor' => 200_000_001], $this->key())->assertStatus(422);
        $this->postJson($url, ['counted_minor' => 149_000_000], $this->key())->assertStatus(422);
        self::assertSame(0, DB::table('fin_petty_settlements')->count());

        $headers = $this->key();
        $s = $this->postJson($url, ['counted_minor' => 150_000_000], $headers)->assertCreated()->json('settlement');
        $this->postJson($url, ['counted_minor' => 150_000_000], $headers);
        self::assertSame(1, DB::table('fin_petty_settlements')->count());
        self::assertSame(['PCS-000001', 'submitted', 50_000_000, 0, 50_000_000], [$s['number'], $s['status'], $s['voucher_total_minor'], $s['variance_minor'], $s['replenish_minor']]);

        $this->postJson($url, ['counted_minor' => 150_000_000], $this->key())->assertStatus(409);
        $this->postJson("/finance/petty/{$fund['id']}/vouchers", ['payee' => 'X', 'description' => 'Y', 'expense_account_id' => $this->account, 'amount_minor' => 1_000], $this->key())->assertStatus(409);
        $this->actAs($this->manager);
        $this->postJson('/finance/petty/vouchers/'.$this->voucherId('PCV-000001').'/void', ['reason' => 'Too late'])->assertStatus(409);
        $this->postJson("/finance/petty/{$fund['id']}", ['name' => 'Closed', 'custodian_id' => (string) $this->custodian->getKey(), 'active' => false, 'lock_version' => 0])->assertStatus(409);

        foreach ([fn () => DB::table('fin_petty_settlements')->update(['counted_minor' => 1]), fn () => DB::table('fin_petty_settlements')->delete(), fn () => DB::table('fin_petty_settlement_vouchers')->delete()] as $change) {
            try {
                $change();
                self::fail('A settlement was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_approval_writes_the_difference_and_replenishes_the_fund_to_its_amount(): void
    {
        $fund = $this->fund();
        $this->spend($fund['id'], $this->custodian, 30_000_000);
        $this->spend($fund['id'], $this->custodian, 20_000_000);
        $this->proof($this->voucherId('PCV-000001'));
        $this->proof($this->voucherId('PCV-000002'));
        $url = "/finance/petty/{$fund['id']}/settlements";

        $this->postJson($url, ['counted_minor' => 149_000_000], $this->key())->assertStatus(422);
        $s = $this->postJson($url, ['counted_minor' => 149_000_000, 'variance_reason' => 'Change given short at the shop'], $this->key())->assertCreated()->json('settlement');
        self::assertSame([-1_000_000, 51_000_000, 150_000_000], [$s['variance_minor'], $s['replenish_minor'], $s['book_minor']]);

        // The custodian cannot decide: no right to. A custodian who also manages cannot decide their own.
        $this->postJson("/finance/petty/settlements/{$s['id']}/decide", ['decision' => 'approve', 'lock_version' => 0])->assertForbidden();
        $this->actAs($this->manager);
        $decide = "/finance/petty/settlements/{$s['id']}/decide";
        $this->postJson($decide, ['decision' => 'reject', 'lock_version' => 0])->assertStatus(422);
        $this->postJson($decide, ['decision' => 'approve', 'lock_version' => 4])->assertStatus(409);
        $this->postJson($decide, ['decision' => 'maybe', 'lock_version' => 0])->assertStatus(422);
        $done = $this->postJson($decide, ['decision' => 'approve', 'note' => 'Checked the notes', 'lock_version' => 0])->assertOk()->json('settlement');
        self::assertSame('approved', $done['status']);
        $this->postJson($decide, ['decision' => 'approve', 'lock_version' => 1])->assertStatus(409);

        $entries = DB::table('fin_petty_entries')->where('fund_id', $fund['id'])->orderBy('seq')->get(['kind', 'signed_minor'])->map(fn ($e): array => [$e->kind, (int) $e->signed_minor])->all();
        self::assertSame([['opening', 200_000_000], ['expense', -30_000_000], ['expense', -20_000_000], ['difference', -1_000_000], ['replenishment', 51_000_000]], $entries);
        self::assertSame(200_000_000, (int) DB::table('fin_petty_entries')->where('fund_id', $fund['id'])->sum('signed_minor'), 'the fund is back to its amount');

        $show = $this->getJson("/finance/petty/{$fund['id']}", ['X-Inertia' => 'true']);
        $this->get("/finance/petty/{$fund['id']}")->assertInertia(fn (Assert $page) => $page->component('finance/pages/petty-fund')->where('fund.balance_minor', 200_000_000)->where('fund.unsettled_count', 0)->where('fund.pending_settlement_id', null)->where('fund.vouchers.0.state', 'settled')->has('fund.settlements', 1));
        self::assertNotNull($show);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'petty_settlement.approved')->first());
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'finance.petty.settlement_approved')->first());

        $this->actAs($this->custodian);
        $this->spend($fund['id'], $this->custodian, 1_000_000);
    }

    public function test_a_rejected_settlement_gives_the_vouchers_back_and_nobody_decides_their_own(): void
    {
        $fund = $this->fund($this->dual);
        $this->spend($fund['id'], $this->dual, 10_000_000);
        $this->proof($this->voucherId('PCV-000001'));
        $s = $this->postJson("/finance/petty/{$fund['id']}/settlements", ['counted_minor' => 190_000_000], $this->key())->assertCreated()->json('settlement');
        $decide = "/finance/petty/settlements/{$s['id']}/decide";

        $this->postJson($decide, ['decision' => 'approve', 'lock_version' => 0])->assertForbidden();
        $this->actAs($this->manager2);
        $this->postJson($decide, ['decision' => 'reject', 'note' => 'The note is for another month', 'lock_version' => 0])->assertOk()->assertJsonPath('settlement.status', 'rejected');
        self::assertSame(190_000_000, (int) DB::table('fin_petty_entries')->sum('signed_minor'), 'a rejection writes nothing');

        $this->get("/finance/petty/{$fund['id']}")->assertInertia(fn (Assert $page) => $page->where('fund.unsettled_count', 1)->where('fund.vouchers.0.state', 'open')->where('fund.pending_settlement_id', null));
        $this->actAs($this->dual);
        $again = $this->postJson("/finance/petty/{$fund['id']}/settlements", ['counted_minor' => 190_000_000], $this->key())->assertCreated()->json('settlement');
        self::assertSame('PCS-000002', $again['number']);

        $this->get("/finance/petty/settlements/{$again['id']}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/petty-settlement')->where('settlement.status', 'submitted')->where('settlement.may_decide', false)->has('settlement.vouchers', 1));
        $this->actAs($this->manager);
        $this->get("/finance/petty/settlements/{$again['id']}")->assertInertia(fn (Assert $page) => $page->where('settlement.may_decide', true));
    }

    public function test_a_fund_is_closed_only_when_it_is_settled_and_a_closed_fund_takes_no_voucher(): void
    {
        $fund = $this->fund();
        $this->spend($fund['id'], $this->custodian, 5_000_000);
        $this->actAs($this->manager);
        $body = ['name' => 'Front office', 'custodian_id' => (string) $this->custodian->getKey(), 'max_voucher_minor' => null, 'active' => false, 'lock_version' => 0];
        $this->postJson("/finance/petty/{$fund['id']}", $body)->assertStatus(409);

        $this->actAs($this->custodian);
        $this->proof($this->voucherId('PCV-000001'));
        $s = $this->postJson("/finance/petty/{$fund['id']}/settlements", ['counted_minor' => 195_000_000], $this->key())->assertCreated()->json('settlement');
        $this->actAs($this->manager);
        $this->postJson("/finance/petty/settlements/{$s['id']}/decide", ['decision' => 'approve', 'lock_version' => 0])->assertOk();

        $this->postJson("/finance/petty/{$fund['id']}", [...$body, 'max_voucher_minor' => 200_000_001])->assertStatus(422);
        $this->postJson("/finance/petty/{$fund['id']}", [...$body, 'lock_version' => 9])->assertStatus(409);
        $closed = $this->postJson("/finance/petty/{$fund['id']}", $body)->assertOk()->json('fund');
        self::assertFalse($closed['active']);
        self::assertNull($closed['max_voucher_minor']);

        $this->actAs($this->custodian);
        $this->postJson("/finance/petty/{$fund['id']}/vouchers", ['payee' => 'X', 'description' => 'Y', 'expense_account_id' => $this->account, 'amount_minor' => 1_000], $this->key())->assertStatus(409);
    }
}
