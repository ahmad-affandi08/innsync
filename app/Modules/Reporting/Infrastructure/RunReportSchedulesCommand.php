<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ReportScheduleService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Every minute, from the scheduler. Asks for the exports of the scheduled reports whose time has come (FR-RPT-004); one property failing must not stop the others. */
final class RunReportSchedulesCommand extends Command
{
    protected $signature = 'reports:run-schedules {--max=20 : Most schedules run for a property in one run}';

    protected $description = 'Run the scheduled reports whose time has come';

    public function handle(ReportScheduleService $schedules, PropertyContext $context): int
    {
        $max = max(1, (int) $this->option('max'));
        $ran = 0;
        $failed = false;

        foreach (DB::table('report_schedules')->where('is_active', true)->where('next_run_at', '<=', now()->utc())->distinct()->pluck('property_id') as $id) {
            try {
                Context::scope(function () use ($schedules, $context, $id, $max, &$ran): void {
                    $property = PropertyId::fromString((string) $id);
                    $ran += $context->run($property, static fn (): int => $schedules->runDue($property, $max));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: scheduled reports failed.");
            }
        }

        $this->line(json_encode(['ran' => $ran, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
