<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Feedback;

use App\Modules\FrontOffice\Application\Feedback\ComplaintWorkload;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseComplaintWorkload implements ComplaintWorkload
{
    public function ownedBy(PropertyId $property, array $userIds, string $fromUtc, string $toUtc): array
    {
        if ($userIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('guest_feedback')->where('property_id', $property->toString())->where('kind', 'complaint')->whereIn('owner_id', $userIds)->where('created_at', '>=', $fromUtc)->where('created_at', '<', $toUtc)
            ->groupBy('owner_id')->selectRaw("owner_id, COUNT(*) as total, SUM(severity IN ('high', 'critical')) as serious, SUM(status IN ('resolved', 'closed')) as resolved")->get() as $r) {
            $out[(string) $r->owner_id] = ['total' => (int) $r->total, 'serious' => (int) $r->serious, 'resolved' => (int) $r->resolved];
        }

        return $out;
    }
}
