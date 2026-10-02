<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The outlets of the hotel beyond rooms and laundry (FR-DSH-005). Revenue is read from the folio postings, each of which says
 * where it came from (its source); an outlet is a name for one or more sources, so a new outlet shows as its own line on the
 * dashboard and its own column of the tax obligations as soon as its sources are named here, with no change to the reports. A
 * source belongs to one outlet; sources named by no outlet stay under "other". Naming or unnaming a source changes how the
 * figures of past days are grouped (never what was charged), which is why each change needs a reason and is audited.
 */
final readonly class OutletService
{
    public const MANAGE_PERMISSION = 'reporting.outlets.manage';

    /** Rooms and laundry are built in: their sources cannot be given to another outlet. */
    public const BUILT_IN_SOURCES = ['night_audit', 'laundry'];

    public const RESERVED_CODES = ['room', 'laundry', 'other', 'net'];

    public function __construct(
        private OutletRepository $outlets,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array{outlets: list<array<string, mixed>>, unmapped: list<string>, built_in: list<array{code: string, sources: list<string>}>} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);

        return [
            'outlets' => $this->outlets->all($property), 'unmapped' => $this->outlets->unmappedSources($property, self::BUILT_IN_SOURCES),
            'built_in' => [['code' => 'room', 'sources' => ['night_audit']], ['code' => 'laundry', 'sources' => ['laundry']]],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $code, string $name, string $reason): array
    {
        $this->authorize($property, $actorId);
        $code = strtolower(trim($code));
        $name = trim($name);
        $this->assertReason($reason);

        if (preg_match('/^[a-z][a-z0-9_]{1,19}$/D', $code) !== 1 || in_array($code, self::RESERVED_CODES, true)) {
            throw Refusal::invalid('Give a code of 2 to 20 lowercase letters, digits or underscores, starting with a letter, other than room, laundry, other and net.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 60) {
            throw Refusal::invalid('Give a name of at most 60 characters.', ['name']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $reason): void {
            if (! $this->outlets->add($property, $id, $code, $name, $this->clock->nowUtc())) {
                throw Refusal::invalid('This code is already used.', ['code']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'outlet.created', 'revenue_outlet', $id, null, ['code' => $code, 'name' => $name], trim($reason)));
        });

        return $this->shown($property, $id);
    }

    /** @return array<string, mixed> */
    public function rename(PropertyId $property, string $actorId, string $id, string $name, int $lock, string $reason): array
    {
        $this->authorize($property, $actorId);
        $name = trim($name);
        $this->assertReason($reason);

        if ($name === '' || mb_strlen($name) > 60) {
            throw Refusal::invalid('Give a name of at most 60 characters.', ['name']);
        }

        $this->transactions->run(function () use ($property, $actorId, $id, $name, $lock, $reason): void {
            $before = $this->outlets->find($property, strtolower($id)) ?? throw Refusal::notFound('Outlet not found.');

            if (! $this->outlets->rename($property, $before['id'], $name, $lock, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This outlet changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'outlet.renamed', 'revenue_outlet', $before['id'], ['name' => $before['name']], ['name' => $name], trim($reason)));
        });

        return $this->shown($property, strtolower($id));
    }

    /** @return array<string, mixed> */
    public function addSource(PropertyId $property, string $actorId, string $id, string $source, string $reason): array
    {
        $this->authorize($property, $actorId);
        $source = strtolower(trim($source));
        $this->assertReason($reason);

        if (preg_match('/^[a-z][a-z0-9_:.-]{1,39}$/D', $source) !== 1) {
            throw Refusal::invalid('A source is 2 to 40 lowercase letters, digits or _ : . -, starting with a letter.', ['source']);
        }

        if (in_array($source, self::BUILT_IN_SOURCES, true)) {
            throw Refusal::invalid('Rooms and laundry are built in; their sources cannot be given to another outlet.', ['source']);
        }

        $this->transactions->run(function () use ($property, $actorId, $id, $source, $reason): void {
            $outlet = $this->outlets->find($property, strtolower($id)) ?? throw Refusal::notFound('Outlet not found.');

            if (! $this->outlets->addSource($property, $outlet['id'], $source, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This source already belongs to an outlet.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'outlet.source_added', 'revenue_outlet', $outlet['id'], null, ['source' => $source, 'outlet' => $outlet['code']], trim($reason)));
        });

        return $this->shown($property, strtolower($id));
    }

    /** @return array<string, mixed> */
    public function removeSource(PropertyId $property, string $actorId, string $id, string $source, string $reason): array
    {
        $this->authorize($property, $actorId);
        $source = strtolower(trim($source));
        $this->assertReason($reason);

        $this->transactions->run(function () use ($property, $actorId, $id, $source, $reason): void {
            $outlet = $this->outlets->find($property, strtolower($id)) ?? throw Refusal::notFound('Outlet not found.');

            if (! $this->outlets->removeSource($property, $outlet['id'], $source)) {
                throw Refusal::notFound('This source does not belong to the outlet.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'outlet.source_removed', 'revenue_outlet', $outlet['id'], ['source' => $source, 'outlet' => $outlet['code']], null, trim($reason)));
        });

        return $this->shown($property, strtolower($id));
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not manage the outlets.');
        }
    }

    /** @return array{id: string, code: string, name: string, lock_version: int, sources: list<string>} */
    private function shown(PropertyId $property, string $id): array
    {
        foreach ($this->outlets->all($property) as $outlet) {
            if ($outlet['id'] === $id) {
                return $outlet;
            }
        }

        throw Refusal::notFound('Outlet not found.');
    }
}
