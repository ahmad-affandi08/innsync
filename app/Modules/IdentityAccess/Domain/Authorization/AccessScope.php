<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Authorization;

use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

final readonly class AccessScope
{
    private const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i';

    private function __construct(
        public PropertyId $propertyId,
        public ScopeType $type,
        private string $scopeId,
    ) {}

    public static function property(PropertyId $propertyId): self
    {
        return new self($propertyId, ScopeType::Property, $propertyId->toString());
    }

    public static function resource(PropertyId $propertyId, ScopeType $type, string $scopeId): self
    {
        if ($type === ScopeType::Property) {
            throw new InvalidArgumentException('Use AccessScope::property for property scope.');
        }

        if (preg_match(self::ULID_PATTERN, $scopeId) !== 1) {
            throw new InvalidArgumentException('Scoped resource ID must be a valid ULID.');
        }

        return new self($propertyId, $type, strtolower($scopeId));
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }
}
