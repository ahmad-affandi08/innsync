<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;

/** The privileges of maintenance, and the property check every maintenance service starts with. */
final readonly class MaintenanceAccess
{
    /** Reports something broken and follows what was reported; held by every department. */
    public const REPORT = 'maintenance.work.report';

    /** Works the jobs given to them: starts, holds, resumes and finishes them. */
    public const PERFORM = 'maintenance.work.perform';

    /** Sees every work order, assigns them, sets their priority, cancels them, takes rooms off sale and sets the service levels. */
    public const MANAGE = 'maintenance.work.manage';

    public function __construct(private PermissionChecker $permissions, private PropertyContext $property) {}

    public function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }

    public function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    public function require(PropertyId $property, string $actorId, string $permission, string $message): void
    {
        $this->assertProperty($property);

        if (! $this->may($property, $actorId, $permission)) {
            throw Refusal::forbidden($message);
        }
    }
}
