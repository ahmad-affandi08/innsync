<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Approval;

use App\Modules\IdentityAccess\Application\Access\DefaultApprovalPolicies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Gives a property the starting approver of each mandatory action it has no policy for. Safe to repeat; a policy that exists is never touched. */
final class DatabaseDefaultApprovalInstaller
{
    /**
     * @return list<string> the actions a starting policy was made for
     *
     * @throws \RuntimeException when the property has nobody the policies can be recorded under
     */
    public function install(string $propertyId): array
    {
        $existing = DB::table('approval_policies')->where('property_id', $propertyId)->whereNull('superseded_at')->pluck('subject_type')->all();
        $missing = array_diff_key(DefaultApprovalPolicies::all(), array_flip($existing));

        if ($missing === []) {
            return [];
        }

        $actor = $this->actor($propertyId);

        if ($actor === null) {
            throw new \RuntimeException("Property {$propertyId} has no administrator to record the starting approval policies under.");
        }

        $now = now()->utc()->format('Y-m-d H:i:s.u');
        $made = [];

        foreach ($missing as $subject => $permission) {
            DB::table('approval_policies')->insert([
                'id' => strtolower((string) Str::ulid()), 'property_id' => $propertyId, 'subject_type' => $subject, 'band_min_amount_minor' => 0, 'version' => 1,
                'steps' => json_encode([['permission' => $permission, 'approvals_required' => 1]], JSON_THROW_ON_ERROR),
                'created_by' => $actor, 'change_reason' => 'Starting policy', 'created_at' => $now,
            ]);
            $made[] = $subject;
        }

        return $made;
    }

    /** The administrator of the property: the oldest active person holding an active Administrator role. */
    private function actor(string $propertyId): ?string
    {
        $id = DB::table('user_role_assignments as a')
            ->join('roles as r', static function ($join): void {
                $join->on('r.id', '=', 'a.role_id')->on('r.property_id', '=', 'a.property_id');
            })
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.property_id', $propertyId)->where('a.is_active', true)->where('r.is_active', true)->where('r.name', 'Administrator')->where('u.is_active', true)
            ->orderBy('u.created_at')->value('u.id');

        return $id === null ? null : strtolower((string) $id);
    }
}
