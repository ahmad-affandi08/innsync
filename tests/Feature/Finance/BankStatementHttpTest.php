<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** The bank statement against the books: a file is checked before it is loaded, loaded once, and each line is tied to a record of the same amount or explained. */
final class BankStatementHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

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
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('mutasi.csv', $body);
    }

    private const FILE = "Date,Description,Debit,Credit\n2026-10-10,TRF MIDTRANS SETTLEMENT,,4850000\n2026-10-11,BIAYA ADMIN,15000,\n";

    /** @return array<string, mixed> */
    private function import(string $body = self::FILE, bool $dry = false, string $label = 'BCA operasional'): array
    {
        return $this->post('/finance/bank/import', ['file' => $this->csv($body), 'account_label' => $label, 'dry_run' => $dry ? '1' : '0'], ['Accept' => 'application/json'])->assertOk()->json();
    }

    private function settlement(int $net, string $on = '2026-10-09'): string
    {
        $id = '01ARZ3NDEKTSV4RRFFQ69G5SET';
        DB::table('fin_settlements')->insert(['id' => $id, 'property_id' => strtoupper(self::A), 'number' => 'SET-0001', 'method' => 'card', 'provider' => 'Midtrans', 'covers_from' => $on, 'covers_to' => $on, 'settled_on' => $on, 'gross_minor' => $net + 10_000, 'fee_minor' => 10_000, 'net_minor' => $net, 'bank_reference' => 'REF1', 'system_minor' => $net + 10_000, 'gross_diff_minor' => 0, 'net_diff_minor' => 0, 'fee_bp' => 0, 'recorded_by' => '01ARZ3NDEKTSV4RRFFQ69G5USR', 'created_at' => now()]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $status = 'open'): array
    {
        $page = null;
        $this->get('/finance/bank'.($status === 'open' ? '' : "?status={$status}"))->assertOk()->assertInertia(function (Assert $p) use (&$page): void {
            $page = $p->toArray()['props']['overview']['lines'];
        });

        return $page;
    }

    public function test_a_file_is_checked_first_then_loaded_once_and_loading_it_again_adds_nothing(): void
    {
        $this->signIn(self::A, [FinanceAccess::BANK_MANAGE]);

        $dry = $this->import(dry: true);
        $this->assertSame(['status' => 'validated', 'dry_run' => true, 'count' => 2, 'duplicates' => 0, 'errors' => []], $dry);
        $this->assertSame([], $this->lines());

        $done = $this->import();
        $this->assertSame('applied', $done['status']);
        $this->assertSame(2, $done['count']);
        $this->assertCount(2, $this->lines());

        $again = $this->import();
        $this->assertSame(0, $again['count']);
        $this->assertSame(2, $again['duplicates']);
        $this->assertCount(2, $this->lines());

        // The same lines on another bank account are other lines.
        $this->assertSame(2, $this->import(label: 'Mandiri')['count']);
    }

    public function test_a_bad_file_is_refused_with_the_lines_that_are_wrong_and_nothing_is_loaded(): void
    {
        $this->signIn(self::A, [FinanceAccess::BANK_MANAGE]);

        $report = $this->import("Date,Description,Debit,Credit\n2026-10-10,OK,,1000\nnot-a-date,BAD,,2000\n");

        $this->assertSame('rejected', $report['status']);
        $this->assertSame(3, $report['errors'][0]['line']);
        $this->assertSame([], $this->lines());
        $this->assertSame('rejected', $this->import("Foo,Bar\n1,2\n")['status']);
    }

    public function test_a_line_is_matched_only_to_a_record_of_the_same_amount_and_direction_and_can_be_reopened(): void
    {
        $this->signIn(self::A, [FinanceAccess::BANK_MANAGE]);
        $settlement = $this->settlement(485_000_000);
        $this->import();

        $in = collect($this->lines())->firstWhere('direction', 'in');
        $out = collect($this->lines())->firstWhere('direction', 'out');
        $this->assertSame([['type' => 'settlement', 'id' => $settlement, 'label' => 'SET-0001 · Midtrans', 'days' => 1]], $in['suggestions']);
        $this->assertSame([], $out['suggestions']);

        // Not the same direction, and not an unknown record.
        $this->postJson("/finance/bank/lines/{$out['id']}/match", ['type' => 'settlement', 'target_id' => $settlement])->assertStatus(422);
        $this->postJson("/finance/bank/lines/{$out['id']}/match", ['type' => 'settlement', 'target_id' => '01ARZ3NDEKTSV4RRFFQ69G5XXX'])->assertStatus(422);

        $this->postJson("/finance/bank/lines/{$in['id']}/match", ['type' => 'settlement', 'target_id' => $settlement])->assertOk();
        $this->assertSame('matched', $this->lines('matched')[0]['status']);
        $this->assertCount(1, $this->lines());

        // A second line for the same settlement cannot take it again, and a settled line cannot be matched twice.
        $this->postJson("/finance/bank/lines/{$in['id']}/match", ['type' => 'settlement', 'target_id' => $settlement])->assertStatus(409);

        $this->postJson("/finance/bank/lines/{$in['id']}/reopen", ['reason' => ''])->assertStatus(422);
        $this->postJson("/finance/bank/lines/{$in['id']}/reopen", ['reason' => 'Matched by mistake'])->assertOk();
        $this->assertCount(2, $this->lines());
        $this->assertSame(2, DB::table('audit_entries')->whereIn('action', ['bank_line.matched', 'bank_line.reopened'])->count());
    }

    public function test_a_line_that_belongs_to_no_record_is_set_aside_with_a_note(): void
    {
        $this->signIn(self::A, [FinanceAccess::BANK_MANAGE]);
        $this->import();
        $fee = collect($this->lines())->firstWhere('direction', 'out');

        $this->postJson("/finance/bank/lines/{$fee['id']}/ignore", ['note' => ''])->assertStatus(422);
        $this->postJson("/finance/bank/lines/{$fee['id']}/ignore", ['note' => 'Bank fee'])->assertOk();

        $aside = $this->lines('ignored');
        $this->assertCount(1, $aside);
        $this->assertSame('Bank fee', $aside[0]['note']);
        $this->postJson("/finance/bank/lines/{$fee['id']}/ignore", ['note' => 'Again'])->assertStatus(409);
    }

    public function test_viewing_needs_the_view_permission_and_changing_needs_the_manage_permission(): void
    {
        $this->signIn(self::A, [FinanceAccess::BANK_MANAGE]);
        $this->import();
        $line = $this->lines()[0];

        $this->post('/logout');
        $this->signIn(self::A, []);
        $this->get('/finance/bank')->assertForbidden();

        $this->post('/logout');
        $this->signIn(self::A, [FinanceAccess::BANK_VIEW]);
        $this->get('/finance/bank')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may_manage', false));
        $this->post('/finance/bank/import', ['file' => $this->csv(self::FILE), 'account_label' => 'X', 'dry_run' => '1'], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/finance/bank/lines/{$line['id']}/ignore", ['note' => 'Bank fee'])->assertForbidden();
    }
}
