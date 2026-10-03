<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The places that sell food and drink and their tables (FR-FBS-001, FR-FBS-008). An outlet has a short code that never changes, a kind, the scheme of service
 * charge and tax it follows (a revenue scope the owner configured under property tax) and whether its prices already include them; by default prices are
 * quoted without, as is usual for hotel menus. Outlets and tables are deactivated, never removed, because bills point at them.
 */
final readonly class OutletService
{
    public const KINDS = ['restaurant', 'bar', 'cafe', 'room_service', 'banquet', 'other'];

    public function __construct(
        private SetupStore $store,
        private FnbAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** The revenue scopes an outlet may follow: those of the property tax setup that are not rooms or laundry. @return list<string> */
    public static function scopes(): array
    {
        return array_values(array_diff(ChargeSchemeService::SCOPES, ['rooms', 'laundry']));
    }

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requireView($property, $actorId);

        return [
            'outlets' => array_map(fn (array $o): array => [...$this->shape($o), 'tables' => count($this->store->tables($property, $o['id']))], $this->store->outlets($property)),
            'kinds' => self::KINDS, 'scopes' => self::scopes(), 'may' => ['manage' => $this->access->may($property, $actorId, FnbAccess::SETUP_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $code, string $name, string $kind, string $scope, bool $pricesIncludeCharges): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not set up outlets.');
        $code = $this->code($code, 12);
        [$name, $kind, $scope] = $this->clean($name, $kind, $scope);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $kind, $scope, $pricesIncludeCharges): void {
            if (! $this->store->addOutlet($property, ['id' => $id, 'code' => $code, 'name' => $name, 'kind' => $kind, 'charge_scope' => $scope, 'prices_include_charges' => $pricesIncludeCharges, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('An outlet with this code already exists.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_outlet.created', 'fnb_outlet', $id, null, ['code' => $code, 'name' => $name, 'kind' => $kind, 'charge_scope' => $scope, 'prices_include_charges' => $pricesIncludeCharges]));
        });

        return $this->shape($this->store->outlet($property, $id) ?? throw Refusal::notFound('Outlet not found.'));
    }

    /** @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, string $name, string $kind, string $scope, bool $pricesIncludeCharges, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not set up outlets.');
        $before = $this->store->outlet($property, strtolower($id)) ?? throw Refusal::notFound('Outlet not found.');
        [$name, $kind, $scope] = $this->clean($name, $kind, $scope);

        $this->transactions->run(function () use ($property, $actorId, $before, $name, $kind, $scope, $pricesIncludeCharges, $active, $lock): void {
            if (! $this->store->updateOutlet($property, $before['id'], $lock, ['name' => $name, 'kind' => $kind, 'charge_scope' => $scope, 'prices_include_charges' => $pricesIncludeCharges, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This outlet changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_outlet.updated', 'fnb_outlet', $before['id'],
                ['name' => $before['name'], 'kind' => $before['kind'], 'charge_scope' => $before['charge_scope'], 'prices_include_charges' => (bool) $before['prices_include_charges'], 'is_active' => (bool) $before['is_active']],
                ['name' => $name, 'kind' => $kind, 'charge_scope' => $scope, 'prices_include_charges' => $pricesIncludeCharges, 'is_active' => $active]));
        });

        return $this->shape($this->store->outlet($property, $before['id']) ?? throw Refusal::notFound('Outlet not found.'));
    }

    /** @return array<string, mixed> the outlet with its tables */
    public function tables(PropertyId $property, string $actorId, string $outletId): array
    {
        $this->access->requireView($property, $actorId);
        $outlet = $this->store->outlet($property, strtolower($outletId)) ?? throw Refusal::notFound('Outlet not found.');

        return ['outlet' => $this->shape($outlet), 'tables' => array_map($this->shapeTable(...), $this->store->tables($property, $outlet['id'])), 'may' => ['manage' => $this->access->may($property, $actorId, FnbAccess::SETUP_MANAGE)]];
    }

    /** @return array<string, mixed> */
    public function addTable(PropertyId $property, string $actorId, string $outletId, string $code, ?string $area, int $seats): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not set up tables.');
        $outlet = $this->store->outlet($property, strtolower($outletId)) ?? throw Refusal::notFound('Outlet not found.');
        $code = $this->code($code, 8);
        [$area, $seats] = $this->cleanTable($area, $seats);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $outlet, $id, $code, $area, $seats): void {
            if (! $this->store->addTable($property, ['id' => $id, 'outlet_id' => $outlet['id'], 'code' => $code, 'area' => $area, 'seats' => $seats, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This outlet has a table with this code already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_table.created', 'fnb_table', $id, null, ['outlet' => $outlet['code'], 'code' => $code, 'area' => $area, 'seats' => $seats]));
        });

        return $this->shapeTable($this->store->table($property, $id) ?? throw Refusal::notFound('Table not found.'));
    }

    /** @return array<string, mixed> */
    public function updateTable(PropertyId $property, string $actorId, string $id, ?string $area, int $seats, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::SETUP_MANAGE, 'This person may not set up tables.');
        $before = $this->store->table($property, strtolower($id)) ?? throw Refusal::notFound('Table not found.');
        [$area, $seats] = $this->cleanTable($area, $seats);

        $this->transactions->run(function () use ($property, $actorId, $before, $area, $seats, $active, $lock): void {
            if (! $this->store->updateTable($property, $before['id'], $lock, ['area' => $area, 'seats' => $seats, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This table changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fnb_table.updated', 'fnb_table', $before['id'], ['area' => $before['area'], 'seats' => (int) $before['seats'], 'is_active' => (bool) $before['is_active']], ['area' => $area, 'seats' => $seats, 'is_active' => $active]));
        });

        return $this->shapeTable($this->store->table($property, $before['id']) ?? throw Refusal::notFound('Table not found.'));
    }

    private function code(string $code, int $max): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,'.($max - 1).'}$/', $code) !== 1) {
            throw Refusal::invalid("The code is 1 to {$max} letters, digits, dot, dash or underscore.", ['code']);
        }

        return $code;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function clean(string $name, string $kind, string $scope): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give the name, at most 80 characters.', ['name']);
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose a kind of outlet.', ['kind']);
        }

        if (! in_array($scope, self::scopes(), true)) {
            throw Refusal::invalid('Choose the service charge and tax scheme of the outlet.', ['charge_scope']);
        }

        return [$name, $kind, $scope];
    }

    /** @return array{0: string|null, 1: int} */
    private function cleanTable(?string $area, int $seats): array
    {
        $area = $area === null ? null : trim($area);
        $area = $area === '' ? null : $area;

        if ($area !== null && mb_strlen($area) > 40) {
            throw Refusal::invalid('The area is at most 40 characters.', ['area']);
        }

        if ($seats < 1 || $seats > 500) {
            throw Refusal::invalid('Give the seats, from 1 to 500.', ['seats']);
        }

        return [$area, $seats];
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function shape(array $o): array
    {
        return ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name'], 'kind' => $o['kind'], 'charge_scope' => $o['charge_scope'], 'prices_include_charges' => (bool) $o['prices_include_charges'], 'is_active' => (bool) $o['is_active'], 'lock_version' => (int) $o['lock_version']];
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function shapeTable(array $t): array
    {
        return ['id' => $t['id'], 'outlet_id' => $t['outlet_id'], 'code' => $t['code'], 'area' => $t['area'], 'seats' => (int) $t['seats'], 'is_active' => (bool) $t['is_active'], 'lock_version' => (int) $t['lock_version']];
    }
}
