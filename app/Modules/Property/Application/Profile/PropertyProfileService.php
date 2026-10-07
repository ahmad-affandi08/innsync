<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Profile;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Setup\ProfileRoles;
use App\Shared\Application\Setup\PropertyProfiles;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Chooses how the property works: its profile and the optional departments it uses (owner instruction 2026-10-07). Changing it hides or shows menu entries and
 * offers the roles that suit a small team; it removes no data, blocks no screen and changes no rule. Needs `property.settings.manage`, a reason, and is audited.
 */
final readonly class PropertyProfileService
{
    public const MANAGE_PERMISSION = 'property.settings.manage';

    public function __construct(
        private PropertyProfileRepository $profiles,
        private ProfileRoles $roles,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private PropertyContext $property,
    ) {}

    /** @param list<string> $disabled */
    public function apply(PropertyId $property, string $actorId, string $profile, array $disabled, string $reason): array
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not change how the property works.');
        }

        if (! PropertyProfiles::isProfile($profile)) {
            throw Refusal::invalid('Choose hotel, small resort or villa.', ['profile']);
        }

        $disabled = array_values(array_unique($disabled));

        if (array_diff($disabled, PropertyProfiles::OPTIONAL_MODULES) !== []) {
            throw Refusal::invalid('Only the optional departments can be switched off.', ['disabled']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw Refusal::invalid('A reason of at most 500 characters is required.', ['reason']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $profile, $disabled, $reason): array {
            $before = $this->profiles->get($property);
            $this->profiles->save($property, $profile, $disabled, $actorId);
            $roles = $this->roles->apply($property, $profile);
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'property.profile.changed', 'property_settings', $property->toString(),
                ['profile' => $before['profile'], 'disabled_modules' => $before['disabled']], ['profile' => $profile, 'disabled_modules' => $disabled, 'roles_created' => $roles['created'], 'roles_deactivated' => $roles['deactivated']], trim($reason)));

            return $roles;
        });
    }

    /** @return array{profile: ?string, disabled: list<string>, presets: array<string, list<string>>, modules: list<string>} */
    public function show(PropertyId $property, string $actorId): array
    {
        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see how the property works.');
        }

        return $this->profiles->get($property) + ['presets' => PropertyProfiles::presets(), 'modules' => PropertyProfiles::OPTIONAL_MODULES];
    }
}
