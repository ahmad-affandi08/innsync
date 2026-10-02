<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tax and service charge obligations (FR-DSH-013, FR-DSH-014): for each calendar month, the regional tax and the service charge
 * collected on the charges posted on its business dates (net of reversals), where they came from, the day the tax is to be reported
 * by, whether it has been reported, and an estimate of the share of the service charge that goes to employees. The day and the share
 * are property settings with an Indonesian baseline (the 15th of the next month; 60 percent) that the owner confirms; the figures are
 * always read from the ledger, and a filing is only a record that someone reported the month, with the amount the system showed.
 */
final readonly class ObligationService
{
    public const VIEW_PERMISSION = 'reporting.obligations.view';

    public const MANAGE_PERMISSION = 'reporting.obligations.manage';

    public const DEFAULT_REPORT_DAY = 15;

    public const DEFAULT_EMPLOYEE_SHARE_BP = 6_000;

    public function __construct(
        private ReportQueries $queries,
        private ObligationRepository $obligations,
        private OutletRepository $outlets,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function timeline(PropertyId $property, string $actorId, int $months = 6): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);

        if ($months < 1 || $months > 24) {
            throw Refusal::invalid('Choose between 1 and 24 months.', ['months']);
        }

        $today = $this->businessDate->current($property);
        $settings = $this->settingsOf($property);
        $current = new DateTimeImmutable(substr($today->toString(), 0, 7).'-01', new DateTimeZone('UTC'));
        $first = $current->modify('-'.($months - 1).' months');
        $figures = $this->queries->obligationsByMonth($property, BusinessDate::fromString($first->format('Y-m-d')), $today);
        $filings = $this->obligations->filings($property);
        $outlets = $this->outlets->all($property);
        $rows = [];
        $total = ['tax' => 0, 'service_charge' => 0, 'employee_estimate' => 0];

        for ($m = $current; $m >= $first; $m = $m->modify('-1 month')) {
            $key = $m->format('Y-m');
            $f = $figures[$key] ?? ['room' => ['base' => 0, 'service_charge' => 0, 'tax' => 0], 'laundry' => ['base' => 0, 'service_charge' => 0, 'tax' => 0], 'other' => ['base' => 0, 'service_charge' => 0, 'tax' => 0]];
            $tax = array_sum(array_column($f, 'tax'));
            $service = array_sum(array_column($f, 'service_charge'));
            $end = $m->modify('last day of this month');
            $due = $m->modify('first day of next month')->modify('+'.($settings['tax_report_day'] - 1).' days');
            $closed = $today->toString() > $end->format('Y-m-d');
            $filing = $filings[$key] ?? null;
            $status = match (true) {
                ! $closed => 'open',
                $filing !== null => 'reported',
                $tax === 0 => 'nothing',
                $today->toString() > $due->format('Y-m-d') => 'overdue',
                default => 'due',
            };
            $estimate = intdiv($service * $settings['service_employee_share_bp'], 10_000);
            $rows[] = [
                'month' => $key, 'tax' => ['room' => $f['room']['tax'], 'laundry' => $f['laundry']['tax'], 'other' => $f['other']['tax'], 'total' => $tax, 'outlets' => $this->byOutlet($outlets, $f, 'tax')],
                'service_charge' => ['room' => $f['room']['service_charge'], 'laundry' => $f['laundry']['service_charge'], 'other' => $f['other']['service_charge'], 'total' => $service, 'outlets' => $this->byOutlet($outlets, $f, 'service_charge')],
                'employee_estimate_minor' => $estimate, 'due_date' => $due->format('Y-m-d'), 'status' => $status, 'filing' => $filing,
            ];
            $total['tax'] += $tax;
            $total['service_charge'] += $service;
            $total['employee_estimate'] += $estimate;
        }

        return [
            'business_date' => $today->toString(), 'rows' => $rows, 'totals' => $total, 'outlets' => array_map(static fn (array $o): array => ['code' => $o['code'], 'name' => $o['name']], $outlets),
            'settings' => [...$settings, 'configured' => $this->obligations->settings($property) !== null],
            'may_manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property),
            'notes' => [
                'Outlets other than rooms and laundry appear under "other" until they are named under the outlets of the reports.',
                'The date and the share are baselines to be confirmed by the owner and the tax consultant.',
            ],
        ];
    }

    /** @return array{tax_report_day: int, service_employee_share_bp: int, lock_version: int|null} */
    public function saveSettings(PropertyId $property, string $actorId, int $reportDay, int $employeeShareBp, ?int $expectedLockVersion, string $reason): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);

        if ($reportDay < 1 || $reportDay > 28 || $employeeShareBp < 0 || $employeeShareBp > 10_000) {
            throw Refusal::invalid('Choose a day from 1 to 28 and a share from 0 to 100 percent.', ['tax_report_day', 'service_employee_share_bp']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $before = $this->settingsOf($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $reportDay, $employeeShareBp, $expectedLockVersion, $reason, $before): void {
            if (! $this->obligations->saveSettings($property, $reportDay, $employeeShareBp, $expectedLockVersion, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('These settings changed after you opened them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'obligation.settings.changed', 'obligation_settings', $property->toString(), ['tax_report_day' => $before['tax_report_day'], 'service_employee_share_bp' => $before['service_employee_share_bp']], ['tax_report_day' => $reportDay, 'service_employee_share_bp' => $employeeShareBp], trim($reason)));
        });

        return $this->settingsOf($property);
    }

    /**
     * Records that the tax of a closed month was reported, with when and the reference of the report. The amount kept is what the
     * ledger showed at that moment. A month is reported once.
     *
     * @return array<string, mixed>
     */
    public function markReported(PropertyId $property, string $actorId, string $month, string $reportedOn, string $reference): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $today = $this->businessDate->current($property);

        try {
            $start = new DateTimeImmutable($month.'-01', new DateTimeZone('UTC'));
            $date = BusinessDate::fromString($reportedOn);
        } catch (\Exception) {
            throw Refusal::invalid('Give the month as YYYY-MM and a valid date.', ['month', 'reported_on']);
        }

        if (preg_match('/^\d{4}-\d{2}$/D', $month) !== 1 || trim($reference) === '' || mb_strlen(trim($reference)) > 60) {
            throw Refusal::invalid('Give the month as YYYY-MM and the reference of the report (at most 60 characters).', ['month', 'reference']);
        }

        $end = $start->modify('last day of this month')->format('Y-m-d');

        if ($today->toString() <= $end) {
            throw Refusal::stateConflict('This month is not over yet: its tax can still change.');
        }

        if ($date->toString() <= $end || $date->isAfter($today)) {
            throw Refusal::invalid('The report was made after the month ended and not after today.', ['reported_on']);
        }

        $figures = $this->queries->obligationsByMonth($property, BusinessDate::fromString($start->format('Y-m-d')), BusinessDate::fromString($end));
        $tax = array_sum(array_column($figures[$month] ?? [], 'tax'));

        if ($tax === 0) {
            throw Refusal::stateConflict('No tax was collected in this month: there is nothing to report.');
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $month, $date, $reference, $tax): void {
            if (! $this->obligations->addFiling($property, $id, $month, $date->toString(), trim($reference), $tax, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This month was already reported.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'obligation.tax.reported', 'tax_filing', $id, null, ['month' => $month, 'reported_on' => $date->toString(), 'tax_minor' => $tax], trim($reference)));
            $this->outbox->publish(new OutboxEvent($property, 'reporting.tax.reported', $id, 1, ['filing_id' => $id, 'month' => $month, 'tax_minor' => $tax, 'reported_on' => $date->toString(), 'actor_id' => $actor]));
        });

        return ['month' => $month, 'reported_on' => $date->toString(), 'reference' => trim($reference), 'tax_minor' => $tax];
    }

    /** @return array{tax_report_day: int, service_employee_share_bp: int, lock_version: int|null} */
    private function settingsOf(PropertyId $property): array
    {
        $saved = $this->obligations->settings($property);

        return $saved ?? ['tax_report_day' => self::DEFAULT_REPORT_DAY, 'service_employee_share_bp' => self::DEFAULT_EMPLOYEE_SHARE_BP, 'lock_version' => null];
    }

    /** @param list<string> $permissions any of these */
    private function authorize(PropertyId $property, string $actorId, array $permissions): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see tax and service charge obligations.');
    }

    /**
     * @param  list<array{code: string}>  $outlets
     * @param  array<string, array<string, int>>  $figures
     * @return array<string, int> the figure of each named outlet, by its code
     */
    private function byOutlet(array $outlets, array $figures, string $field): array
    {
        $result = [];

        foreach ($outlets as $outlet) {
            $result[$outlet['code']] = $figures['outlet:'.$outlet['code']][$field] ?? 0;
        }

        return $result;
    }
}
