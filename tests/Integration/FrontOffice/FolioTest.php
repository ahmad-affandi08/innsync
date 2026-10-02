<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\ApprovalRequired;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Shared\Application\Approval\ApprovalNotUsable;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Approval\MissingApprovalPolicy;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class FolioTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $reservationId;

    private string $folioId;

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
        $this->reservationId = $this->book()->id;
        $this->folioId = $this->folios()->open($this->property(), $this->managerId, $this->reservationId)['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function folios(): FolioService
    {
        return app(FolioService::class);
    }

    private function folioView(): array
    {
        return $this->folios()->view($this->property(), $this->managerId, $this->folioId);
    }

    private function balance(): int
    {
        return $this->folioView()['balance_minor'];
    }

    private function expect(callable $attempt, int $status): void
    {
        try {
            $attempt();
            self::fail('Accepted');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    /** Policy and supervisor approval for sensitive corrections. */
    private function requireApprovals(): void
    {
        $admin = app(ApprovalPolicyAdmin::class);

        foreach (['front-office.folio.reversal', 'front-office.folio.refund'] as $subject) {
            $admin->define($this->property(), $this->adminId, $subject, 0, [['permission' => 'front-office.folio.approve']], 'Initial chain');
        }
    }

    private function approved(string $subject, string $ref, array $payload, int $amount, string $maker): string
    {
        $svc = app(ApprovalService::class);
        $view = $svc->request(new ApprovalRequestInput($this->property(), $subject, $ref, $maker, 'Guest asked', $payload, null, $amount, 'IDR'), IdempotencyKey::fromString('appr-'.bin2hex(random_bytes(8))));
        $svc->approve($this->property(), $view->id, $this->supervisorId);

        return $view->id;
    }

    public function test_opening_a_folio_numbers_it_audits_it_and_allows_one_per_window(): void
    {
        self::assertSame('FOL-000001', $this->folioView()['number']);
        self::assertSame(['open', 0, 'IDR'], [$this->folioView()['status'], $this->balance(), $this->folioView()['currency']]);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'folio.opened')->first());

        $this->expect(fn () => $this->folios()->open($this->property(), $this->managerId, $this->reservationId), 422);
        $company = $this->folios()->open($this->property(), $this->managerId, $this->reservationId, 'Company', 2);
        self::assertSame('FOL-000002', $company['number']);
        self::assertCount(2, $this->folios()->forReservation($this->property(), $this->viewerId, $this->reservationId));

        $cancelled = $this->book('2026-11-10', '2026-11-11');
        app(ReservationService::class)->cancel($this->property(), $this->managerId, $cancelled->id, 'Guest cancelled', 0);
        $this->expect(fn () => $this->folios()->open($this->property(), $this->managerId, $cancelled->id), 409);
    }

    public function test_a_charge_is_split_into_base_service_charge_and_tax_posted_in_order_and_audited(): void
    {
        $posted = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Room 101, night of 2026-10-10', 100_000_000, false);

        self::assertSame([100_000_000, 10_000_000, 11_000_000, 121_000_000, 1, '2026-10-01'], [
            $posted['posting']['base_minor'], $posted['posting']['service_charge_minor'], $posted['posting']['tax_minor'], $posted['posting']['total_minor'], $posted['posting']['seq'], $posted['posting']['business_date'],
        ]);
        self::assertSame(121_000_000, $posted['folio']['balance_minor']);
        self::assertSame(121_000_000, $posted['folio']['charges_minor']);

        $second = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'LAUNDRY', 'Guest laundry', 50_000_000, false);
        self::assertSame(2, $second['posting']['seq']);
        self::assertSame(181_500_000, $second['folio']['balance_minor']);

        $snapshot = json_decode((string) DB::table('folio_postings')->where('seq', 1)->value('scheme_snapshot'), true);
        self::assertSame([1000, 1000, true, false], [$snapshot['service_charge_bp'], $snapshot['tax_bp'], $snapshot['tax_on_service_charge'], $snapshot['prices_include_charges']]);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'folio.charge.posted')->count());
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'frontoffice.folio.charge.posted')->count());
    }

    public function test_a_charge_with_a_source_reference_is_posted_once_however_often_it_is_delivered(): void
    {
        $first = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Night audit', 100_000_000, false, 'night-audit:2026-10-01:RSV1');
        $again = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Night audit', 100_000_000, false, 'night-audit:2026-10-01:RSV1');

        self::assertFalse($first['replayed']);
        self::assertTrue($again['replayed']);
        self::assertSame($first['posting']['id'], $again['posting']['id']);
        self::assertSame(1, DB::table('folio_postings')->count());
        self::assertSame(121_000_000, $this->balance());

        $this->expect(fn () => $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Night audit', 90_000_000, false, 'night-audit:2026-10-01:RSV1'), 409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'folio.charge.posted')->count());
    }

    public function test_payments_by_each_method_move_the_balance_and_the_derived_status(): void
    {
        $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Room', 100_000_000, false);
        self::assertSame('open', $this->folioView()['status']);

        $cash = $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 50_000_000, null, 'settlement');
        self::assertSame(['partially_settled', 71_000_000], [$cash['folio']['status'], $cash['folio']['balance_minor']]);
        self::assertSame(-50_000_000, $cash['posting']['total_minor']);

        $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'qris', 30_000_000, 'QRIS-884213', 'settlement');
        $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'card', 20_000_000, 'EDC-APPR-4471', 'settlement');
        $last = $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'bank_transfer', 21_000_000, 'TRF 2026-10-01 #77', 'settlement');
        self::assertSame(['settled', 0, 121_000_000], [$last['folio']['status'], $last['folio']['balance_minor'], $last['folio']['payments_minor']]);
        self::assertSame(['CASH', 'QRIS', 'CARD', 'BANK_TRANSFER'], DB::table('folio_postings')->where('entry_type', 'payment')->orderBy('seq')->pluck('code')->all());
    }

    public function test_payment_input_is_validated_and_a_card_number_is_never_stored(): void
    {
        foreach ([
            ['qris', 1_000, null, 422], ['card', 1_000, '', 422], ['cash', 0, null, 422], ['cash', -5, null, 422], ['wire', 1_000, 'x', 422],
            ['card', 1_000, '4111 1111 1111 1111', 422], ['card', 1_000, 'slip 4111111111111111', 422], ['card', 1_000, 'bad<script>', 422],
        ] as [$method, $amount, $reference, $status]) {
            $this->expect(fn () => $this->folios()->pay($this->property(), $this->managerId, $this->folioId, $method, $amount, $reference, 'settlement'), $status);
        }

        $this->expect(fn () => $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 1_000, null, 'tip'), 422);
        self::assertSame(0, DB::table('folio_postings')->count());
    }

    public function test_a_deposit_before_any_charge_leaves_a_credit_that_settles_later_charges(): void
    {
        $deposit = $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'bank_transfer', 150_000_000, 'DEP-TRF-9', 'deposit');
        self::assertSame([-150_000_000, 'deposit', 'open'], [$deposit['folio']['balance_minor'], $deposit['posting']['payment_purpose'], $deposit['folio']['status']]);

        $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Two nights', 200_000_000, false);
        self::assertSame(92_000_000, $this->balance(), 'the deposit is applied automatically: 2.42M owed minus 1.5M deposit');
    }

    public function test_closing_needs_a_zero_balance_and_a_closed_folio_refuses_every_posting(): void
    {
        $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Room', 100_000_000, false);
        $version = $this->folioView()['lock_version'];

        $this->expect(fn () => $this->folios()->close($this->property(), $this->managerId, $this->folioId, $version), 409);
        $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 121_000_000, null, 'settlement');
        $this->expect(fn () => $this->folios()->close($this->property(), $this->managerId, $this->folioId, $version), 409); // stale

        $closed = $this->folios()->close($this->property(), $this->managerId, $this->folioId, $this->folioView()['lock_version']);
        self::assertSame('closed', $closed['status']);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'folio.closed')->first());

        $this->expect(fn () => $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Late', 100, false), 409);
        $this->expect(fn () => $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 100, null, 'settlement'), 409);
        $this->expect(fn () => $this->folios()->close($this->property(), $this->managerId, $this->folioId, $closed['lock_version']), 409);
    }

    public function test_a_charge_on_an_open_folio_is_corrected_by_an_exact_mirror_once_and_the_original_stays(): void
    {
        $charge = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Wrong night', 100_000_000, false)['posting'];

        $this->expect(fn () => $this->folios()->reverse($this->property(), $this->managerId, $charge['id'], '  '), 422);
        $reversed = $this->folios()->reverse($this->property(), $this->managerId, $charge['id'], 'Posted to the wrong room');

        self::assertSame([-100_000_000, -10_000_000, -11_000_000, -121_000_000, 'reversal', $charge['id']], [
            $reversed['posting']['base_minor'], $reversed['posting']['service_charge_minor'], $reversed['posting']['tax_minor'], $reversed['posting']['total_minor'], $reversed['posting']['type'], $reversed['posting']['reverses_id'],
        ]);
        self::assertSame(0, $reversed['folio']['balance_minor']);
        self::assertSame(2, DB::table('folio_postings')->count());
        self::assertSame(121_000_000, (int) DB::table('folio_postings')->where('id', $charge['id'])->value('total_minor'), 'the original posting is untouched');

        $this->expect(fn () => $this->folios()->reverse($this->property(), $this->managerId, $charge['id'], 'Again'), 409);
        $this->expect(fn () => $this->folios()->reverse($this->property(), $this->managerId, $reversed['posting']['id'], 'Undo the reversal'), 409);
    }

    public function test_reversing_a_payment_fails_closed_without_a_policy_and_then_needs_a_single_use_approval_by_the_same_person(): void
    {
        $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Room', 100_000_000, false);
        $payment = $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 121_000_000, null, 'settlement')['posting'];

        try {
            $this->folios()->reverse($this->property(), $this->managerId, $payment['id'], 'Entered twice');
            self::fail('A payment was reversed with no approval policy configured');
        } catch (MissingApprovalPolicy) {
            self::assertSame(0, DB::table('folio_postings')->where('entry_type', 'reversal')->count());
        }

        $this->requireApprovals();

        try {
            $this->folios()->reverse($this->property(), $this->managerId, $payment['id'], 'Entered twice');
            self::fail('Reversed without an approval');
        } catch (ApprovalRequired $e) {
            self::assertSame('approval_required', $e->conflict()['reason']);
        }

        $request = $this->folios()->requestReversalApproval($this->property(), $this->managerId, $payment['id'], 'Entered twice', IdempotencyKey::fromString('rev-req-000000000001'));
        self::assertSame('pending', $request->status);

        try {
            $this->folios()->reverse($this->property(), $this->managerId, $payment['id'], 'Entered twice', $request->id);
            self::fail('Used an approval nobody decided');
        } catch (ApprovalNotUsable) {
            $this->addToAssertionCount(1);
        }

        app(ApprovalService::class)->approve($this->property(), $request->id, $this->supervisorId);
        $done = $this->folios()->reverse($this->property(), $this->managerId, $payment['id'], 'Entered twice', $request->id);

        self::assertSame(121_000_000, $done['folio']['balance_minor']);
        self::assertSame($request->id, DB::table('folio_postings')->where('entry_type', 'reversal')->value('approval_id'));
        self::assertSame($request->id, DB::table('audit_entries')->where('action', 'folio.posting.reversed')->value('approval_reference'));

        // The same approval cannot be used again, and one approved for another posting cannot be borrowed.
        $second = $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 121_000_000, null, 'settlement')['posting'];
        $this->expectApprovalRefused(fn () => $this->folios()->reverse($this->property(), $this->managerId, $second['id'], 'Entered twice', $request->id));
        self::assertSame(1, DB::table('folio_postings')->where('entry_type', 'reversal')->count());
    }

    public function test_the_person_who_asked_is_the_only_one_who_can_use_an_approval(): void
    {
        $this->requireApprovals();
        $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Room', 100_000_000, false);
        $payment = $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 121_000_000, null, 'settlement')['posting'];
        $request = $this->folios()->requestReversalApproval($this->property(), $this->managerId, $payment['id'], 'Entered twice', IdempotencyKey::fromString('rev-req-000000000002'));
        app(ApprovalService::class)->approve($this->property(), $request->id, $this->supervisorId);

        $other = UserRecord::factory()->create();
        $this->grant($other, self::PROPERTY, [FolioService::MANAGE_PERMISSION, FolioService::CORRECT_PERMISSION]);

        $this->expectApprovalRefused(fn () => $this->folios()->reverse($this->property(), strtolower((string) $other->getKey()), $payment['id'], 'Entered twice', $request->id));
    }

    public function test_a_settled_folio_needs_approval_even_to_correct_a_charge(): void
    {
        $this->requireApprovals();
        $charge = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Room', 100_000_000, false)['posting'];
        $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 121_000_000, null, 'settlement');

        $this->expectApprovalRequired(fn () => $this->folios()->reverse($this->property(), $this->managerId, $charge['id'], 'Wrong night'));
    }

    public function test_the_unused_part_of_a_deposit_is_refunded_only_with_an_approval_for_that_exact_amount(): void
    {
        $this->requireApprovals();
        $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'bank_transfer', 300_000_000, 'DEP-1', 'deposit');
        $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Stay', 200_000_000, false);
        self::assertSame(-58_000_000, $this->balance());

        $this->expectApprovalRequired(fn () => $this->folios()->refund($this->property(), $this->managerId, $this->folioId, 'bank_transfer', 58_000_000, 'REF-1', 'Unused deposit', null));

        $request = $this->folios()->requestRefundApproval($this->property(), $this->managerId, $this->folioId, 'bank_transfer', 58_000_000, 'Unused deposit', IdempotencyKey::fromString('refund-req-00000001'));
        app(ApprovalService::class)->approve($this->property(), $request->id, $this->supervisorId);

        $this->expectApprovalRefused(fn () => $this->folios()->refund($this->property(), $this->managerId, $this->folioId, 'bank_transfer', 60_000_000, 'REF-1', 'More than approved', $request->id));
        $done = $this->folios()->refund($this->property(), $this->managerId, $this->folioId, 'bank_transfer', 58_000_000, 'REF-1', 'Unused deposit', $request->id);

        self::assertSame([0, 'refund', 58_000_000], [$done['folio']['balance_minor'], $done['posting']['type'], $done['posting']['total_minor']]);
        $closed = $this->folios()->close($this->property(), $this->managerId, $this->folioId, $done['folio']['lock_version']);
        self::assertSame('closed', $closed['status']);
    }

    public function test_permissions_and_property_scope_are_enforced(): void
    {
        $this->expect(fn () => $this->folios()->charge($this->property(), $this->viewerId, $this->folioId, 'ROOM', 'x', 100, false), 403);
        $this->expect(fn () => $this->folios()->pay($this->property(), $this->viewerId, $this->folioId, 'cash', 100, null, 'settlement'), 403);
        $this->expect(fn () => $this->folios()->reverse($this->property(), $this->viewerId, '01arz3ndektsv4rrffq69g5fax', 'x'), 403);
        $this->expect(fn () => $this->folios()->view($this->property(), $this->supervisorId, $this->folioId), 403);
        self::assertSame(0, $this->folios()->view($this->property(), $this->viewerId, $this->folioId)['balance_minor']);
        $this->expect(fn () => $this->folios()->view($this->property(), $this->managerId, '01arz3ndektsv4rrffq69g5fax'), 404);

        $this->expectException(PropertyScopeViolation::class);
        $this->folios()->view(PropertyId::fromString('01arz3ndektsv4rrffq69g5faw'), $this->managerId, $this->folioId);
    }

    public function test_the_database_makes_the_ledger_immutable_and_rejects_impossible_rows(): void
    {
        $charge = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'ROOM', 'Room', 100_000_000, false)['posting'];
        $base = [
            'id' => '01arz3ndektsv4rrffq69g5fb1', 'property_id' => self::PROPERTY, 'folio_id' => $this->folioId, 'seq' => 99, 'entry_type' => 'charge', 'code' => 'ROOM', 'description' => 'x',
            'currency_code' => 'IDR', 'base_minor' => 100, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 100, 'business_date' => '2026-10-01', 'posted_at' => now(), 'source' => 'front_office',
        ];

        foreach ([
            'update' => fn () => DB::table('folio_postings')->where('id', $charge['id'])->update(['total_minor' => 1]),
            'delete' => fn () => DB::table('folio_postings')->where('id', $charge['id'])->delete(),
            'charge total mismatch' => fn () => DB::table('folio_postings')->insert([...$base, 'total_minor' => 101]),
            'negative charge' => fn () => DB::table('folio_postings')->insert([...$base, 'base_minor' => -100, 'total_minor' => -100]),
            'positive payment' => fn () => DB::table('folio_postings')->insert([...$base, 'entry_type' => 'payment', 'payment_method' => 'cash', 'payment_purpose' => 'settlement']),
            'payment without method' => fn () => DB::table('folio_postings')->insert([...$base, 'entry_type' => 'payment', 'base_minor' => 0, 'total_minor' => -100, 'payment_purpose' => 'settlement']),
            'reversal without reason' => fn () => DB::table('folio_postings')->insert([...$base, 'entry_type' => 'reversal', 'reverses_id' => $charge['id'], 'base_minor' => -100, 'total_minor' => -100]),
            'duplicate seq' => fn () => DB::table('folio_postings')->insert([...$base, 'seq' => 1]),
            'refund without reason' => fn () => DB::table('folio_postings')->insert([...$base, 'entry_type' => 'refund', 'base_minor' => 0, 'total_minor' => 100, 'payment_method' => 'cash']),
            'folio delete' => fn () => DB::table('folios')->where('id', $this->folioId)->delete(),
            'folio identity' => fn () => DB::table('folios')->where('id', $this->folioId)->update(['number' => 'FOL-999999']),
            'closed without who' => fn () => DB::table('folios')->where('id', $this->folioId)->update(['status' => 'closed']),
        ] as $name => $change) {
            try {
                $change();
                self::fail("{$name} was accepted");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(1, DB::table('folio_postings')->count());
    }

    public function test_after_any_sequence_of_operations_the_stored_balance_equals_the_ledger_sum(): void
    {
        mt_srand(20261001);
        $repo = app(FolioRepository::class);
        $posted = [];

        for ($i = 0; $i < 40; $i++) {
            $roll = mt_rand(1, 10);

            if ($roll <= 4) {
                $posted[] = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'MISC', 'Item '.$i, mt_rand(1, 50) * 100_000, (bool) mt_rand(0, 1))['posting']['id'];
            } elseif ($roll <= 7) {
                $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', mt_rand(1, 40) * 100_000, null, mt_rand(0, 1) ? 'deposit' : 'settlement');
            } elseif ($posted !== []) {
                $id = array_splice($posted, mt_rand(0, count($posted) - 1), 1)[0];

                try {
                    $this->folios()->reverse($this->property(), $this->managerId, $id, 'Random correction');
                } catch (MissingApprovalPolicy|Refusal) {
                    // A settled folio would need approval; the invariant must hold regardless.
                }
            }

            self::assertSame($repo->recomputeBalance($this->property(), $this->folioId), $this->balance(), "after step {$i}");
        }

        $seqs = DB::table('folio_postings')->orderBy('seq')->pluck('seq')->all();
        self::assertSame(range(1, count($seqs)), array_map('intval', $seqs), 'sequence numbers are gapless');
    }

    private function expectApprovalRequired(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Accepted without approval');
        } catch (ApprovalRequired) {
            $this->addToAssertionCount(1);
        }
    }

    private function expectApprovalRefused(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Accepted with an approval that does not fit');
        } catch (ApprovalNotUsable) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_bill_lists_charges_by_outlet_then_payments_and_what_is_still_owed(): void
    {
        app(ChargeSchemeService::class)->define($this->property(), $this->adminId, 'laundry', '2026-10-01', '10', '10', true, 'Regional regulation');
        $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'MINIBAR', 'Minibar', 10_000_000, false);
        $this->folios()->postGuestCharge($this->property(), $this->managerId, $this->reservationId, 'laundry', 'LAUNDRY', 'Guest laundry LDY-1', 50_000_000, 'laundry', 'ldy:1');
        $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'qris', 20_000_000, 'QR-9', 'deposit');

        $bill = $this->folios()->bill($this->property(), $this->viewerId, $this->folioId);

        self::assertSame(['IDR', 'Budi Santoso'], [$bill['currency'], $bill['reservation']['guest_name']]);
        self::assertSame(['laundry', 'other'], array_column($bill['outlets'], 'outlet'), 'outlets in a fixed order');
        self::assertSame(['Guest laundry LDY-1'], array_column($bill['outlets'][0]['lines'], 'description'));
        self::assertSame(12_100_000, $bill['outlets'][1]['total_minor']);
        self::assertSame([20_000_000, 'qris', 'QR-9'], [$bill['payments'][0]['amount_minor'], $bill['payments'][0]['method'], $bill['payments'][0]['reference']]);
        self::assertSame($bill['totals']['total'] - 20_000_000, $bill['totals']['balance_minor']);
        self::assertSame($this->balance(), $bill['totals']['balance_minor'], 'the printed balance is the folio balance');
        self::assertSame($bill['totals']['base'] + $bill['totals']['service_charge'] + $bill['totals']['tax'], $bill['totals']['total']);

        $this->expect(fn () => $this->folios()->bill($this->property(), $this->dashOnlyId, $this->folioId), 403);
        $this->expect(fn () => $this->folios()->bill($this->property(), $this->viewerId, '01arz3ndektsv4rrffq69g5fax'), 404);
    }

    public function test_a_charge_is_moved_to_another_folio_of_the_booking_by_a_reversal_and_a_new_charge(): void
    {
        $company = $this->folios()->open($this->property(), $this->managerId, $this->reservationId, 'Company', 2)['id'];
        $charge = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'MINIBAR', 'Minibar', 100_000_000, false)['posting'];

        $targets = $this->folios()->transferTargets($this->property(), $this->managerId, $this->folioId);
        self::assertSame([$company], array_column($targets['same'], 'folio_id'));
        self::assertSame('Company', $targets['same'][0]['label']);

        $moved = $this->folios()->transfer($this->property(), $this->managerId, $charge['id'], $company, 'The company pays the minibar');
        self::assertSame([0, 121_000_000], [$moved['from']['balance_minor'], $moved['to']['balance_minor']]);
        self::assertSame(['charge', 'MINIBAR', 'transfer', 'The company pays the minibar'], [$moved['posting']['type'], $moved['posting']['code'], DB::table('folio_postings')->where('id', $moved['posting']['id'])->value('source'), $moved['posting']['reason']]);
        self::assertStringStartsWith('Moved from FOL-000001: Minibar', $moved['posting']['description']);
        self::assertSame([100_000_000, 10_000_000, 11_000_000], [$moved['posting']['base_minor'], $moved['posting']['service_charge_minor'], $moved['posting']['tax_minor']]);
        self::assertSame(3, DB::table('folio_postings')->count());
        self::assertSame(121_000_000, (int) DB::table('folio_postings')->where('id', $charge['id'])->value('total_minor'), 'the original stays');
        self::assertSame(1, DB::table('folio_postings')->where('reverses_id', $charge['id'])->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'folio.posting.moved_out')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'folio.posting.moved_in')->count());

        // Nothing is created or lost: the ledger sums to what was charged, and what was moved cannot be moved from the same place twice.
        self::assertSame(121_000_000, (int) DB::table('folio_postings')->sum('total_minor'));
        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->managerId, $charge['id'], $company, 'Again'), 409);

        // It can be moved back, and a payment, a reversal or a move to the same folio is refused.
        $back = $this->folios()->transfer($this->property(), $this->managerId, $moved['posting']['id'], $this->folioId, 'A mistake');
        self::assertSame([121_000_000, 0], [$back['to']['balance_minor'], $back['from']['balance_minor']]);
        $payment = $this->folios()->pay($this->property(), $this->managerId, $this->folioId, 'cash', 1_000_000, null, 'settlement')['posting'];
        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->managerId, $payment['id'], $company, 'x'), 409);
        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->managerId, $back['posting']['id'], $this->folioId, 'x'), 422);
        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->managerId, $back['posting']['id'], $company, ' '), 422);
        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->managerId, $back['posting']['id'], '01arz3ndektsv4rrffq69g5faa', 'x'), 422);
        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->viewerId, $back['posting']['id'], $company, 'x'), 403);
        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->managerId, '01arz3ndektsv4rrffq69g5faa', $company, 'x'), 404);
    }

    public function test_a_closed_folio_neither_gives_nor_takes_a_charge(): void
    {
        $company = $this->folios()->open($this->property(), $this->managerId, $this->reservationId, 'Company', 2)['id'];
        $charge = $this->folios()->charge($this->property(), $this->managerId, $this->folioId, 'MINIBAR', 'Minibar', 10_000_000, false)['posting'];
        $this->folios()->close($this->property(), $this->managerId, $company, 0);

        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->managerId, $charge['id'], $company, 'To a closed folio'), 409);
        self::assertSame([], $this->folios()->transferTargets($this->property(), $this->managerId, $this->folioId)['same']);
    }

    public function test_moving_a_charge_to_another_guests_folio_needs_the_right_to_correct_folios(): void
    {
        $other = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($other->id, $this->roomIds[1], 'Siti', 'ID', 'ktp', '3174010101900002', null, null, 'Jl. Merdeka 2', 2, 0), IdempotencyKey::fromString('folio-move-checkin-1'));
        $otherFolio = $this->folios()->forReservation($this->property(), $this->managerId, $other->id)[0]['id'];
        $charge = $this->folios()->charge($this->property(), $this->cashierId, $this->folioId, 'MINIBAR', 'Minibar', 10_000_000, false)['posting'];

        self::assertSame([], $this->folios()->transferTargets($this->property(), $this->cashier2Id, $this->folioId)['others'], 'only someone who may correct folios sees other guests');
        $others = $this->folios()->transferTargets($this->property(), $this->managerId, $this->folioId)['others'];
        self::assertSame([[$otherFolio, '102', 'Budi Santoso']], array_map(static fn (array $f): array => [$f['folio_id'], $f['room'], $f['guest']], $others));

        $this->expect(fn () => $this->folios()->transfer($this->property(), $this->cashier2Id, $charge['id'], $otherFolio, 'Wrong room'), 403);
        $moved = $this->folios()->transfer($this->property(), $this->managerId, $charge['id'], $otherFolio, 'Posted to the wrong room');
        self::assertSame([0, 12_100_000], [$moved['from']['balance_minor'], $moved['to']['balance_minor']]);
    }
}
