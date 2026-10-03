<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How the service charge is shared (FR-HR-032): the share of what was collected that goes to the staff, the reserve kept back from it for damage and loss, the points of each position, and the points of a position that has
 * none. Until the owner saves them the usual values apply (all of it to the staff, 5% reserve, one point); they are the property's policy to set, not a rule of the system.
 */
final readonly class ServiceChargeSettingsService
{
    public const BASELINE = ['staff_share_bp' => 10_000, 'reserve_bp' => 500, 'default_points_x100' => 100];

    public function __construct(
        private ServiceChargeStore $store,
        private HrAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function get(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::SERVICE_CHARGE, 'This person may not see how the service charge is shared.');

        return $this->current($property);
    }

    /** @return array<string, mixed> */
    public function current(PropertyId $property): array
    {
        $this->access->assertProperty($property);
        $r = $this->store->settings($property);

        return [
            'staff_share_bp' => (int) ($r['staff_share_bp'] ?? self::BASELINE['staff_share_bp']), 'reserve_bp' => (int) ($r['reserve_bp'] ?? self::BASELINE['reserve_bp']), 'default_points_x100' => (int) ($r['default_points_x100'] ?? self::BASELINE['default_points_x100']),
            'points' => array_map(static fn (array $p): array => ['position' => $p['position'], 'points_x100' => (int) $p['points_x100']], $this->store->points($property)), 'is_baseline' => $r === null, 'lock_version' => $r === null ? null : (int) $r['lock_version'],
        ];
    }

    /**
     * @param  list<array{position: string, points_x100: int}>  $points
     * @return array<string, mixed>
     */
    public function save(PropertyId $property, string $actorId, int $staffShareBp, int $reserveBp, int $defaultPointsX100, array $points, ?int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::SERVICE_CHARGE, 'This person may not set how the service charge is shared.');

        if ($staffShareBp < 0 || $staffShareBp > 10_000 || $reserveBp < 0 || $reserveBp > 10_000) {
            throw Refusal::invalid('A share is from 0 to 100 percent.', ['staff_share_bp', 'reserve_bp']);
        }

        if ($defaultPointsX100 < 1 || $defaultPointsX100 > 10_000) {
            throw Refusal::invalid('Points are from 0.01 to 100.', ['default_points_x100']);
        }

        if (count($points) > 100) {
            throw Refusal::invalid('Give the points of at most 100 positions.', ['points']);
        }

        $clean = [];

        foreach ($points as $p) {
            $position = trim((string) ($p['position'] ?? ''));
            $key = mb_strtolower($position);
            $x100 = $p['points_x100'] ?? null;

            if ($position === '' || mb_strlen($position) > 80 || ! is_int($x100) || $x100 < 1 || $x100 > 10_000) {
                throw Refusal::invalid('Each position has a name of at most 80 characters and points from 0.01 to 100.', ['points']);
            }

            if (isset($clean[$key])) {
                throw Refusal::invalid('A position is listed once.', ['points']);
            }

            $clean[$key] = ['position_key' => $key, 'position' => $position, 'points_x100' => $x100];
        }

        $before = $this->current($property);
        $actor = strtolower($actorId);
        $values = ['staff_share_bp' => $staffShareBp, 'reserve_bp' => $reserveBp, 'default_points_x100' => $defaultPointsX100];

        $this->transactions->run(function () use ($property, $actor, $values, $clean, $lock, $before): void {
            if ($before['lock_version'] !== $lock || ! $this->store->saveSettings($property, $values, $before['lock_version'], $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('These settings changed after you opened them. Reload them.');
            }

            $this->store->replacePoints($property, array_values($clean));
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'service_charge_settings.changed', 'service_charge_settings', $property->toString(), ['staff_share_bp' => $before['staff_share_bp'], 'reserve_bp' => $before['reserve_bp'], 'default_points_x100' => $before['default_points_x100'], 'positions' => count($before['points'])], [...$values, 'positions' => count($clean)]));
        });

        return $this->current($property);
    }
}
