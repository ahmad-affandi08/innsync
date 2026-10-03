<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;

/**
 * The trail of every action that changes a financial figure (FR-FIN-035): who did it, when, on what, the figures before and after, the reason and the approval. It reads
 * the audit trail the actions already write; nothing is kept twice. `finance` is what finance records (payables, payments, receivables, receipts, petty cash, budgets,
 * corrections, exceptions, exports); `front_office` is the money the front office posts (folio postings, refunds, cashier shifts, night audits).
 */
final readonly class FinanceAuditService
{
    public const GROUPS = [
        'finance' => ['budget.', 'cash_deposit.', 'cash_exception.', 'cash_opening.', 'correction.', 'customer.', 'expense_account.', 'fin_exception.', 'finance.', 'payable.', 'petty_', 'pnl.', 'receipt.', 'receivable.', 'recurring_expense.', 'revenue_day.', 'supplier_payment.'],
        'front_office' => ['cashier.', 'folio.', 'night_audit.'],
    ];

    public const PAGE_SIZE = 50;

    public function __construct(private FinanceAuditQueries $queries, private FinanceAccess $access, private BusinessDateProvider $businessDate, private PropertyTimeZoneReader $zones, private StaffDirectory $staff) {}

    /** @return array<string, mixed> */
    public function trail(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $group, ?string $user, ?string $action, int $page): array
    {
        $this->access->require($property, $actorId, FinanceAccess::AUDIT_VIEW, 'This person may not see the finance audit trail.');
        $group = $group === null || $group === '' ? 'finance' : $group;

        if (! isset(self::GROUPS[$group])) {
            throw Refusal::invalid('Choose finance or front_office.', ['group']);
        }

        if ($action !== null && $action !== '' && preg_match('/^[a-z][a-z0-9_.]{1,118}$/D', $action) !== 1) {
            throw Refusal::invalid('An action is a short lowercase name such as supplier_payment.recorded.', ['action']);
        }

        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $today = $this->businessDate->current($property);

        try {
            $fromDate = CalendarDate::fromString($from === null || $from === '' ? $today->addDays(-6)->toString() : $from);
            $toDate = CalendarDate::fromString($to === null || $to === '' ? $today->toString() : $to);
        } catch (InvalidArgumentException) {
            throw Refusal::invalid('Give valid dates.', ['from', 'to']);
        }

        if ($toDate->isBefore($fromDate) || $fromDate->daysUntil($toDate) > 92) {
            throw Refusal::invalid('Choose a range of at most 93 days, ending after it starts.', ['from', 'to']);
        }

        $result = $this->queries->trail($property, self::GROUPS[$group], $zone->utcAt($fromDate), $zone->utcAt($toDate->next()), ['actor_id' => $user === '' ? null : $user, 'action' => $action === '' ? null : $action], self::PAGE_SIZE, max(0, $page - 1) * self::PAGE_SIZE);

        return [
            'from' => $fromDate->toString(), 'to' => $toDate->toString(), 'group' => $group, 'groups' => array_keys(self::GROUPS), 'rows' => $result['rows'], 'total' => $result['total'], 'page' => max(1, $page), 'page_size' => self::PAGE_SIZE,
            'staff' => array_values($this->staff->withPermission($property, FinanceAccess::AUDIT_VIEW)),
        ];
    }
}
