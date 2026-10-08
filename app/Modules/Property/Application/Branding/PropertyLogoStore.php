<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Branding;

use App\Shared\Domain\Tenancy\PropertyId;

interface PropertyLogoStore
{
    /** @return array{mime: string, content: string, sha256: string}|null */
    public function find(PropertyId $property): ?array;

    /** The fingerprint only, so the header can know a logo exists without reading the picture. */
    public function hash(PropertyId $property): ?string;

    public function save(PropertyId $property, string $mime, string $content, string $sha256, string $actorId): void;

    public function remove(PropertyId $property): bool;

    /** Shown unless the property switched it off. */
    public function poweredBy(PropertyId $property): bool;

    public function setPoweredBy(PropertyId $property, bool $show, string $actorId): void;

    /** The only active property of the installation, for the sign-in page; null when there are none or several. */
    public function soleProperty(): ?PropertyId;
}
