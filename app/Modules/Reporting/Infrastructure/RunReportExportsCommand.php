<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ExportJobService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Every minute, from the scheduler. Builds the report exports people asked for (FR-RPT-011); one property failing must not stop the others. */
final class RunReportExportsCommand extends Command
{
    protected $signature = 'reports:run-exports {--max=5 : Most exports built for a property in one run}';

    protected $description = 'Build the report exports that were asked for and are waiting';

    public function handle(ExportJobService $exports, PropertyContext $context): int
    {
        $max = max(1, (int) $this->option('max'));
        $built = 0;
        $failed = false;

        foreach (DB::table('report_export_jobs')->whereIn('status', ['queued', 'running'])->distinct()->pluck('property_id') as $id) {
            try {
                Context::scope(function () use ($exports, $context, $id, $max, &$built): void {
                    $property = PropertyId::fromString((string) $id);
                    $built += $context->run($property, static fn (): int => $exports->runQueued($property, $max));
                });
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Property {$id}: report exports failed.");
            }
        }

        $this->line(json_encode(['built' => $built, 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
