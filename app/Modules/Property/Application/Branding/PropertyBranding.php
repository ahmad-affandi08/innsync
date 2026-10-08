<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Branding;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/** The logo of the property: who may change it, what is kept, and what the header and the printed documents show. Every change is audited. */
final readonly class PropertyBranding
{
    public const MANAGE_PERMISSION = 'property.settings.manage';

    public function __construct(
        private PropertyLogoStore $logos,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private PropertyContext $property,
    ) {}

    /** @return array{has_logo: bool, version: string|null} */
    public function show(PropertyId $property): array
    {
        $hash = $this->logos->hash($property);

        return ['has_logo' => $hash !== null, 'version' => $hash === null ? null : substr($hash, 0, 12)];
    }

    /** The header's address for the logo, or null when the property has none. */
    public function url(PropertyId $property): ?string
    {
        $hash = $this->logos->hash($property);

        return $hash === null ? null : '/property/logo?v='.substr($hash, 0, 12);
    }

    /** @return array{mime: string, content: string, sha256: string}|null */
    public function picture(PropertyId $property): ?array
    {
        return $this->logos->find($property);
    }

    public function replace(PropertyId $property, string $actorId, string $bytes): void
    {
        $this->authorize($property, $actorId);
        $logo = LogoImage::accept($bytes);
        $hash = hash('sha256', $logo['content']);
        $before = $this->logos->hash($property);

        $this->transactions->run(function () use ($property, $actorId, $logo, $hash, $before): void {
            $this->logos->save($property, $logo['mime'], $logo['content'], $hash, strtolower($actorId));
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'property.logo.replaced', 'property_logo', $property->toString(), $before === null ? null : ['sha256' => $before], ['sha256' => $hash, 'mime' => $logo['mime'], 'bytes' => strlen($logo['content'])]));
        });
    }

    public function remove(PropertyId $property, string $actorId): void
    {
        $this->authorize($property, $actorId);
        $before = $this->logos->hash($property);

        if ($before === null) {
            return;
        }

        $this->transactions->run(function () use ($property, $actorId, $before): void {
            $this->logos->remove($property);
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'property.logo.removed', 'property_logo', $property->toString(), ['sha256' => $before], null));
        });
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        if (! $this->property->current()->equals($property)) {
            throw PropertyScopeViolation::mismatched($this->property->current()->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not change the logo.');
        }
    }
}
