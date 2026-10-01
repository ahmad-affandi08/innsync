<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Privacy;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Time\Clock;
use Illuminate\Support\Facades\DB;

/** A data subject request past its response target is a legal-risk signal (NFR-08, NFR-20). */
final readonly class PrivacyRequestsCheck implements HealthCheck
{
    public function __construct(private Clock $clock) {}

    public function name(): string
    {
        return 'privacy_requests';
    }

    public function check(): HealthResult
    {
        $open = DB::table('data_subject_requests')->whereIn('status', ['received', 'in_progress']);
        $overdue = (clone $open)->where('due_at', '<', $this->clock->nowUtc())->count();
        $context = ['open_requests' => (clone $open)->count(), 'overdue_requests' => $overdue];

        return $overdue > 0
            ? HealthResult::down('A personal data request is past its response target.', $context)
            : HealthResult::ok('No personal data request is overdue.', $context);
    }
}
