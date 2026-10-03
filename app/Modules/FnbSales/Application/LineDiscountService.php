<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * A discount, or an item given free, on one line of an open bill (FR-FBS-006). The person who gives it needs the privilege, says why, and gets an approval when the
 * property's policy asks for one: for a discount the threshold is the amount band of the policy `fnb.discount` (with no policy no discount needs approval, the owner decides
 * the threshold); a complimentary item is mandatory, so with no policy `fnb.comp` it is refused, never given. The line keeps what it came to and what was taken off, and
 * its total is what is left, so the charges, the revenue and the payments follow what was really billed. A discount is taken off, or replaced, before anything is paid; the
 * kitchen still cooks a complimentary dish and the pantry still gives its ingredients.
 */
final readonly class LineDiscountService
{
    public const DISCOUNT_SUBJECT = 'fnb.discount';

    public const COMP_SUBJECT = 'fnb.comp';

    public const KINDS = ['percent', 'amount', 'comp'];

    public function __construct(
        private BillStore $bills,
        private BillGuard $guard,
        private FnbAccess $access,
        private ApprovalGate $approvals,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** Opens the approval a discount or a complimentary item needs. @return array<string, mixed> */
    public function request(PropertyId $property, string $actorId, string $billId, string $lineId, string $kind, ?int $value, string $reason, IdempotencyKey $key): array
    {
        $this->access->require($property, $actorId, FnbAccess::DISCOUNT_APPLY, 'This person may not give discounts.');
        $reason = $this->reason($reason);
        $bill = $this->bills->bill($property, strtolower($billId)) ?? throw Refusal::notFound('Bill not found.');
        $line = $this->line($bill, $lineId);
        [$minor, $value] = $this->assertGivable($bill, $line, $kind, $value);

        $view = $this->approvals->request(new ApprovalRequestInput(
            $property, $this->subject($kind), $line['id'], strtolower($actorId), $reason, $this->payload($bill, $line, $kind, $value, $minor),
            ['bill' => $bill['number'], 'item' => $line['item_name'], 'kind' => $kind, 'discount_minor' => $minor], $kind === 'comp' ? $this->gross($line) : $minor, $this->currencies->currencyOf($property),
        ), $key);

        return ['approval' => $view->toArray()];
    }

    public function apply(PropertyId $property, string $actorId, string $billId, string $lineId, string $kind, ?int $value, string $reason, ?string $approvalId, int $lock): void
    {
        $this->access->require($property, $actorId, FnbAccess::DISCOUNT_APPLY, 'This person may not give discounts.');
        $reason = $this->reason($reason);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $lineId, $kind, $value, $reason, $approvalId, $lock): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $line = $this->line($bill, $lineId);
            [$minor, $value] = $this->assertGivable($bill, $line, $kind, $value);
            $this->assertNoPayments($property, $bill['id']);
            $approval = $this->consume($property, $actor, $this->subject($kind), $line['id'], $this->payload($bill, $line, $kind, $value, $minor), $kind === 'comp' ? $this->gross($line) : $minor, $approvalId);
            $now = $this->clock->nowUtc();
            $before = $line['discount_kind'] === null ? null : ['kind' => $line['discount_kind'], 'value' => $line['discount_value'] === null ? null : (int) $line['discount_value'], 'discount_minor' => (int) $line['discount_minor']];
            $this->bills->updateLine($property, $bill['id'], $line['id'], [
                'gross_minor' => $this->gross($line), 'discount_kind' => $kind, 'discount_value' => $value, 'discount_minor' => $minor, 'discount_reason' => $reason, 'discount_by' => $actor,
                'discount_approval_id' => $approval === '' ? null : $approval, 'discounted_at' => $now, 'line_total_minor' => $this->gross($line) - $minor,
            ], $now);
            $this->guard->touch($property, $bill['id'], $lock);
            $this->audit->record(new AuditEntry($property->toString(), $actor, $kind === 'comp' ? 'fnb_line.comped' : 'fnb_line.discounted', 'fnb_bill', $bill['id'], $before,
                ['line' => $line['line_no'], 'item' => $line['item_name'], 'kind' => $kind, 'value' => $value, 'gross_minor' => $this->gross($line), 'discount_minor' => $minor], $reason, $approval === '' ? null : $approval));
        });
    }

    /** Takes a discount off again. */
    public function remove(PropertyId $property, string $actorId, string $billId, string $lineId, string $reason, int $lock): void
    {
        $this->access->require($property, $actorId, FnbAccess::DISCOUNT_APPLY, 'This person may not give discounts.');
        $reason = $this->reason($reason);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $billId, $lineId, $reason, $lock): void {
            $bill = $this->guard->open($property, strtolower($billId), $lock);
            $line = $this->line($bill, $lineId);

            if ($line['discount_kind'] === null) {
                throw Refusal::stateConflict('This line has no discount.');
            }

            $this->assertNoPayments($property, $bill['id']);
            $now = $this->clock->nowUtc();
            $this->bills->updateLine($property, $bill['id'], $line['id'], [
                'discount_kind' => null, 'discount_value' => null, 'discount_minor' => 0, 'discount_reason' => null, 'discount_by' => null, 'discount_approval_id' => null, 'discounted_at' => null, 'line_total_minor' => $this->gross($line),
            ], $now);
            $this->guard->touch($property, $bill['id'], $lock);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_line.discount_removed', 'fnb_bill', $bill['id'],
                ['line' => $line['line_no'], 'item' => $line['item_name'], 'kind' => $line['discount_kind'], 'discount_minor' => (int) $line['discount_minor'], 'approval' => $line['discount_approval_id']], ['discount_minor' => 0], $reason));
        });
    }

    /**
     * What a discount of this kind takes off the line: a percentage is in basis points and rounds half up; an amount is in minor units. Nothing may take off nothing, or
     * the whole line (that is a complimentary item, which has its own approval).
     *
     * @param  array<string, mixed>  $bill
     * @param  array<string, mixed>  $line
     * @return array{0: int, 1: int|null} the amount taken off and the value to keep
     */
    private function assertGivable(array $bill, array $line, string $kind, ?int $value): array
    {
        if ($bill['status'] !== 'open' || ! in_array($line['status'], ['pending', 'sent'], true)) {
            throw Refusal::stateConflict('A discount is given on a line of an open bill that was not voided or removed.');
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose a percentage, an amount or complimentary.', ['kind']);
        }

        $gross = $this->gross($line);

        if ($kind === 'comp') {
            return [$gross, null];
        }

        if ($value === null || $value < 1) {
            throw Refusal::invalid($kind === 'percent' ? 'Give the percentage, above zero.' : 'Give the amount, above zero.', ['value']);
        }

        $minor = $kind === 'percent' ? intdiv($gross * min($value, 10_000) + 5_000, 10_000) : $value;

        if ($kind === 'percent' && $value >= 10_000 || $minor < 1 || $minor >= $gross) {
            throw Refusal::invalid('A discount is more than nothing and less than the whole line. To give the line free, choose complimentary.', ['value']);
        }

        return [$minor, $value];
    }

    /**
     * @param  array<string, mixed>  $bill
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function payload(array $bill, array $line, string $kind, ?int $value, int $minor): array
    {
        return ['bill_id' => $bill['id'], 'line_id' => $line['id'], 'kind' => $kind, 'value' => $value, 'discount_minor' => $minor, 'gross_minor' => $this->gross($line)];
    }

    private function subject(string $kind): string
    {
        return $kind === 'comp' ? self::COMP_SUBJECT : self::DISCOUNT_SUBJECT;
    }

    /** @param array<string, mixed> $line */
    private function gross(array $line): int
    {
        return (int) ($line['gross_minor'] ?? $line['line_total_minor']);
    }

    /**
     * @param  array<string, mixed>  $bill
     * @return array<string, mixed>
     */
    private function line(array $bill, string $lineId): array
    {
        foreach ($bill['lines'] as $l) {
            if ($l['id'] === strtolower($lineId)) {
                return $l;
            }
        }

        throw Refusal::notFound('Line not found.');
    }

    /** Fails closed when the subject is mandatory and the property has no policy; returns the approval used, or '' when none is needed. @param array<string, mixed> $payload */
    private function consume(PropertyId $property, string $actor, string $subject, string $ref, array $payload, int $amount, ?string $approvalId): string
    {
        if (! $this->approvals->requirementFor($property, $subject, $amount)->required) {
            return '';
        }

        if ($approvalId === null || $approvalId === '') {
            throw new ApprovalRequired;
        }

        $this->approvals->consume($property, strtolower($approvalId), $subject, $ref, $payload, $actor);

        return strtolower($approvalId);
    }

    private function assertNoPayments(PropertyId $property, string $billId): void
    {
        if ($this->bills->paymentCount($property, $billId) > 0) {
            throw Refusal::stateConflict('This bill has payments. A discount is given before anything is paid.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        return $reason;
    }
}
