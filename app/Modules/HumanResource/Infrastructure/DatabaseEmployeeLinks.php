<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Infrastructure;

use App\Shared\Application\Security\EmployeeLinks;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseEmployeeLinks implements EmployeeLinks
{
    public function ofUsers(PropertyId $property, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('hr_employees')->where('property_id', $property->toString())->whereIn('user_id', $userIds)->get(['id', 'number', 'full_name', 'department', 'position', 'status', 'user_id']) as $e) {
            $out[strtolower((string) $e->user_id)] = ['id' => (string) $e->id, 'number' => (string) $e->number, 'name' => (string) $e->full_name, 'department' => (string) $e->department, 'position' => (string) $e->position, 'status' => (string) $e->status];
        }

        return $out;
    }
}
