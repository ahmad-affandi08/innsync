<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** The register of fixed assets: straight-line depreciation of each closed month, once; an asset that leaves; and the journal. */
final class FixedAssetHttpTest extends TestCase
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
        $this->signIn(self::A, [FinanceAccess::ASSET_MANAGE, FinanceAccess::EXPORT, PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-15', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function body(array $override = []): array
    {
        return ['code' => 'FURN1', 'name' => 'Lobby sofas', 'category' => 'furniture', 'acquired_on' => '2026-07-10', 'cost_minor' => 1_200_000_000, 'salvage_minor' => 0, 'life_months' => 12, ...$override];
    }

    /** @return list<array<string, string>> */
    private function journal(string $from, string $to): array
    {
        $rows = [];

        foreach (explode("\r\n", ltrim($this->get("/finance/export/journal?from={$from}&to={$to}")->assertOk()->getContent(), "\xEF\xBB\xBF")) as $line) {
            if ($line !== '') {
                $rows[] = str_getcsv($line);
            }
        }

        $header = array_shift($rows);

        return array_map(fn (array $r): array => array_combine($header, $r), $rows);
    }

    public function test_each_closed_month_is_depreciated_once_for_every_asset_and_the_journal_carries_it(): void
    {
        $this->postJson('/finance/assets', $this->body())->assertCreated();
        $this->postJson('/finance/assets', $this->body())->assertStatus(422);
        $this->postJson('/finance/assets', $this->body(['code' => 'X', 'salvage_minor' => 1_200_000_000]))->assertStatus(422);
        $this->postJson('/finance/assets', $this->body(['code' => 'X', 'acquired_on' => '2026-10-16']))->assertStatus(422);
        $this->postJson('/finance/assets', $this->body(['code' => 'X', 'category' => 'boat']))->assertStatus(422);
        $this->postJson('/finance/assets', $this->body(['code' => 'X', 'life_months' => 0]))->assertStatus(422);

        $this->get('/finance/assets')->assertOk()->assertInertia(fn (Assert $p) => $p->component('finance/pages/assets')->where('overview.through', '2026-09')->where('overview.pending_months', 3)->where('overview.assets.0.monthly_minor', 100_000_000)->where('overview.totals.book_minor', 1_200_000_000));

        // October is not over: only September and before.
        $this->postJson('/finance/assets/depreciation', ['through' => '2026-10'])->assertStatus(422);
        $this->postJson('/finance/assets/depreciation', ['through' => '2026-09'])->assertOk()->assertJsonPath('run.months', 3)->assertJsonPath('run.amount_minor', 300_000_000);
        $this->postJson('/finance/assets/depreciation', ['through' => '2026-09'])->assertOk()->assertJsonPath('run.months', 0);
        self::assertSame(['2026-07', '2026-08', '2026-09'], DB::table('fin_depreciation_entries')->orderBy('period')->pluck('period')->all());
        self::assertSame(300_000_000, (int) DB::table('fin_depreciation_entries')->max('accumulated_after_minor'));
        $this->get('/finance/assets')->assertInertia(fn (Assert $p) => $p->where('overview.pending_months', 0)->where('overview.assets.0.book_minor', 900_000_000)->where('overview.assets.0.depreciated_through', '2026-09'));

        $rows = $this->journal('2026-07-01', '2026-09-30');
        $dep = array_values(array_filter($rows, fn (array $r): bool => str_starts_with($r['Journal'], 'DEP-')));
        self::assertSame(['2026-07-31', '2026-08-31', '2026-09-30'], array_values(array_unique(array_column($dep, 'Date'))));
        self::assertSame(['5-6000' => '1000000.00'], array_column(array_filter($dep, fn (array $r): bool => $r['Date'] === '2026-08-31' && $r['Debit'] !== '0.00'), 'Debit', 'Account code'));
        self::assertSame(['1-3900' => '1000000.00'], array_column(array_filter($dep, fn (array $r): bool => $r['Date'] === '2026-08-31' && $r['Credit'] !== '0.00'), 'Credit', 'Account code'));
        self::assertEqualsWithDelta(array_sum(array_column($rows, 'Debit')), array_sum(array_column($rows, 'Credit')), 0.001);
    }

    public function test_an_asset_that_leaves_stops_depreciating_and_the_journal_shows_the_gain_or_the_loss(): void
    {
        $this->postJson('/finance/assets', $this->body(['code' => 'TV1', 'name' => 'Room TVs', 'acquired_on' => '2026-08-05', 'cost_minor' => 600_000_000, 'life_months' => 6]))->assertCreated();
        $id = (string) DB::table('fin_fixed_assets')->value('id');

        // The depreciation must be up to date before it leaves; then it is sold for more than its book value.
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'sale', 'disposed_on' => '2026-10-05', 'proceeds_minor' => 450_000_000, 'method' => 'transfer', 'reason' => 'Replaced by smart TVs', 'lock_version' => 0])->assertStatus(409);
        $this->postJson('/finance/assets/depreciation', ['through' => '2026-09'])->assertOk()->assertJsonPath('run.months', 2);
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'scrap', 'disposed_on' => '2026-10-05', 'proceeds_minor' => 1, 'reason' => 'x', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'sale', 'disposed_on' => '2026-10-05', 'proceeds_minor' => 450_000_000, 'reason' => 'x', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'sale', 'disposed_on' => '2026-07-01', 'proceeds_minor' => 0, 'reason' => 'x', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'sale', 'disposed_on' => '2026-10-05', 'proceeds_minor' => 450_000_000, 'method' => 'transfer', 'reason' => 'Replaced by smart TVs', 'lock_version' => 3])->assertStatus(409);
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'sale', 'disposed_on' => '2026-10-05', 'proceeds_minor' => 450_000_000, 'method' => 'transfer', 'reason' => 'Replaced by smart TVs', 'lock_version' => 0])->assertOk();
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'lost', 'disposed_on' => '2026-10-06', 'proceeds_minor' => 0, 'reason' => 'Again', 'lock_version' => 1])->assertStatus(409);

        // Nothing more is depreciated after it left, and its facts cannot be changed.
        $this->postJson('/finance/assets/depreciation', ['through' => '2026-09'])->assertOk()->assertJsonPath('run.months', 0);
        self::assertSame(2, DB::table('fin_depreciation_entries')->count());
        $this->expectException(QueryException::class);
        DB::table('fin_fixed_assets')->where('id', $id)->update(['cost_minor' => 1]);
    }

    public function test_the_disposal_in_the_journal_takes_the_asset_and_its_depreciation_out_and_books_the_difference(): void
    {
        $this->postJson('/finance/assets', $this->body(['code' => 'TV1', 'name' => 'Room TVs', 'acquired_on' => '2026-08-05', 'cost_minor' => 600_000_000, 'life_months' => 6]))->assertCreated();
        $id = (string) DB::table('fin_fixed_assets')->value('id');
        $this->postJson('/finance/assets/depreciation', ['through' => '2026-09'])->assertOk();
        $this->postJson("/finance/assets/{$id}/dispose", ['kind' => 'sale', 'disposed_on' => '2026-10-05', 'proceeds_minor' => 450_000_000, 'method' => 'transfer', 'reason' => 'Replaced', 'lock_version' => 0])->assertOk();

        $rows = array_values(array_filter($this->journal('2026-10-01', '2026-10-31'), fn (array $r): bool => str_starts_with($r['Journal'], 'DSP-')));
        $of = fn (string $code, string $side): string => array_values(array_filter($rows, fn (array $r): bool => $r['Account code'] === $code))[0][$side];
        self::assertSame('2000000.00', $of('1-3900', 'Debit'), 'its depreciation goes with it');
        self::assertSame('4500000.00', $of('1-1120', 'Debit'), 'what the buyer paid');
        self::assertSame('6000000.00', $of('1-3000', 'Credit'), 'the asset leaves at its cost');
        self::assertSame('500000.00', $of('4-2000', 'Credit'), 'sold for 500,000 more than the 4,000,000 book value');

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [FinanceAccess::ASSET_VIEW]);
        $this->get('/finance/assets')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may_manage', false));
        $this->postJson('/finance/assets', $this->body(['code' => 'N']))->assertForbidden();
        $this->postJson('/finance/assets/depreciation', ['through' => '2026-09'])->assertForbidden();
    }
}
