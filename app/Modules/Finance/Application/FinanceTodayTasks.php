<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Supplier payables overdue or due within a week, and revenue days still to verify (the last 30 days). */
final readonly class FinanceTodayTasks implements TodayTasks
{
    public function __construct(private PayableService $payables, private RevenueService $revenue, private BusinessDateProvider $businessDate) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        $tasks = [];

        try {
            $open = $this->payables->overview($property, $actorId, 'open', null)['payables'];
            $tasks[] = ['key' => 'fin.overdue', 'count' => count(array_filter($open, static fn (array $p): bool => $p['overdue'])), 'href' => '/finance/payables?status=overdue', 'urgent' => true];
            $tasks[] = ['key' => 'fin.due', 'count' => count(array_filter($open, static fn (array $p): bool => ! $p['overdue'] && $p['days_to_due'] <= 7)), 'href' => '/finance/schedule'];
        } catch (Refusal) {
        }

        try {
            $to = $this->businessDate->current($property)->toString();
            $from = (new DateTimeImmutable($to))->modify('-30 days')->format('Y-m-d');
            $tasks[] = ['key' => 'fin.unverified', 'count' => (int) $this->revenue->report($property, $actorId, $from, $to)['unverified'], 'href' => '/finance/revenue'];
        } catch (Refusal) {
        }

        return $tasks;
    }
}
