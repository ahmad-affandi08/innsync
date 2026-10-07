<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Setup;

use App\Shared\Application\Approval\ApprovalSubjects;
use App\Shared\Application\Setup\ModuleSettings;
use App\Shared\Application\Setup\SetupFacts;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseSetupFacts implements SetupFacts
{
    public function __construct(private ApprovalSubjects $subjects, private ModuleSettings $modules) {}

    public function forProperty(PropertyId $property): array
    {
        $p = $property->toString();
        $count = static fn (string $table, bool $activeOnly = false): int => (int) DB::table($table)->where('property_id', $p)->when($activeOnly, static fn ($q) => $q->where('is_active', true))->count();

        $mandatory = array_keys(array_filter($this->subjects->all()));
        $covered = DB::table('approval_policies')->where('property_id', $p)->whereNull('superseded_at')->whereIn('subject_type', $mandatory)->distinct()->pluck('subject_type')->all();

        $off = [];
        foreach ($this->modules->disabled($property) as $module) {
            $off['off.'.$module] = 1;
        }

        return $off + [
            'profile' => $count('property_profiles'),
            'approvals_single' => $this->approvalsWithOneApprover($p, $mandatory),
            'settings' => $count('property_settings'),
            'business_date' => (int) DB::table('property_settings')->where('property_id', $p)->whereNotNull('business_date')->count(),
            'room_types' => $count('room_types', true),
            'rooms' => $count('rooms', true),
            'rate_plans' => $count('rate_plans', true),
            'rate_periods' => $count('rate_periods'),
            'charge_schemes' => $count('charge_schemes'),
            'booking_policies' => $count('booking_policies'),
            'roles' => (int) DB::table('roles')->where('property_id', $p)->where('is_active', true)->where('name', '<>', 'Administrator')->where('name', 'not like', 'System:%')->count(),
            'people' => (int) DB::table('users')->where('is_active', true)->whereExists(static fn ($q) => $q->selectRaw('1')->from('user_role_assignments')->whereColumn('user_role_assignments.user_id', 'users.id')->where('user_role_assignments.property_id', $p)->where('user_role_assignments.is_active', true))->count(),
            'approvals_total' => count($mandatory),
            'approvals_missing' => count(array_diff($mandatory, $covered)),
            'fnb_outlets' => $count('fnb_outlets', true),
            'fnb_items' => $count('fnb_menu_items', true),
            'laundry_prices' => $count('laundry_price_items', true),
            'hk_templates' => $count('hk_checklist_templates', true),
            'inventory_items' => $count('inventory_items', true),
            'suppliers' => $count('suppliers', true),
            'employees' => (int) DB::table('hr_employees')->where('property_id', $p)->where('status', 'active')->count(),
            'expense_accounts' => $count('finance_expense_accounts', true),
        ];
    }

    /**
     * How many mandatory actions can be approved by only one person. The person who asks never approves their own request (BR-004), so an action with a single
     * possible approver is stuck whenever that person is the one asking: the hotel needs a second person with the manager permission.
     *
     * @param  list<string>  $mandatory
     */
    private function approvalsWithOneApprover(string $propertyId, array $mandatory): int
    {
        $holders = [];
        $single = 0;

        foreach (DB::table('approval_policies')->where('property_id', $propertyId)->whereNull('superseded_at')->whereIn('subject_type', $mandatory)->get(['subject_type', 'steps']) as $policy) {
            $steps = json_decode((string) $policy->steps, true);
            $permission = is_array($steps) && isset($steps[0]['permission']) ? (string) $steps[0]['permission'] : null;

            if ($permission === null) {
                continue;
            }

            $holders[$permission] ??= (int) DB::table('user_role_assignments as a')
                ->join('roles as r', static function ($join): void {
                    $join->on('r.id', '=', 'a.role_id')->on('r.property_id', '=', 'a.property_id');
                })
                ->join('role_permissions as g', static function ($join): void {
                    $join->on('g.role_id', '=', 'r.id')->on('g.property_id', '=', 'r.property_id');
                })
                ->join('permissions as p', 'p.id', '=', 'g.permission_id')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.property_id', $propertyId)->where('a.scope_type', 'property')->where('a.is_active', true)->where('r.is_active', true)->where('u.is_active', true)->where('p.code', $permission)
                ->distinct()->count('a.user_id');

            if ($holders[$permission] < 2) {
                $single++;
            }
        }

        return $single;
    }
}
