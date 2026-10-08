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

    /** @return array{has_logo: bool, version: string|null, logo_url: string|null, show_powered_by: bool} */
    public function show(PropertyId $property): array
    {
        $hash = $this->logos->hash($property);

        return ['has_logo' => $hash !== null, 'version' => $hash === null ? null : substr($hash, 0, 12), 'logo_url' => $this->address($property, $hash), 'show_powered_by' => $this->logos->poweredBy($property)];
    }

    /**
     * What every page shows of the property's brand: its logo address and whether "Powered by InnSYnc" is shown. The logo is public by its address (a guest
     * and the sign-in page need it before anyone is signed in), as any hotel's logo is; it holds nothing private.
     *
     * @return array{logoUrl: string|null, poweredBy: bool}
     */
    public function brand(PropertyId $property): array
    {
        return ['logoUrl' => $this->address($property, $this->logos->hash($property)), 'poweredBy' => $this->logos->poweredBy($property)];
    }

    /** The brand of the only property of the installation, for the sign-in page; none when there are several, since the page cannot know which one is meant. */
    public function soleBrand(): ?array
    {
        $property = $this->logos->soleProperty();

        return $property === null ? null : $this->brand($property);
    }

    private function address(PropertyId $property, ?string $hash): ?string
    {
        return $hash === null ? null : '/brand/'.$property->toString().'/logo?v='.substr($hash, 0, 12);
    }

    public function setPoweredBy(PropertyId $property, string $actorId, bool $show): void
    {
        $this->authorize($property, $actorId);
        $before = $this->logos->poweredBy($property);

        if ($before === $show) {
            return;
        }

        $this->transactions->run(function () use ($property, $actorId, $show, $before): void {
            $this->logos->setPoweredBy($property, $show, strtolower($actorId));
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'property.powered_by.changed', 'property_branding', $property->toString(), ['show_powered_by' => $before], ['show_powered_by' => $show]));
        });
    }

    /** @return array{mime: string, content: string, sha256: string}|null the logo of a property by its address; none for an address that is not a property */
    public function pictureOf(string $property): ?array
    {
        try {
            return $this->logos->find(PropertyId::fromString($property));
        } catch (\InvalidArgumentException) {
            return null;
        }
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
