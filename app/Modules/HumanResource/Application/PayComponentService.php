<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The kinds of earning (FR-HR-030). Basic pay and a fixed allowance are paid whole every period; a variable allowance follows the days a person was there out of the days planned; a meal and a transport allowance
 * are paid for each day present. Each says whether it is taxable and whether it counts in the wage the social security is worked out on. A kind is changed (with the version of the screen) or retired, never deleted.
 */
final readonly class PayComponentService
{
    public const KINDS = ['basic', 'fixed_allowance', 'variable_allowance', 'meal', 'transport'];

    /** The usual ones in Indonesian hotels: basic pay and the fixed allowance count in the social security wage; all are taxable. @var list<array{0: string, 1: string, 2: string, 3: bool, 4: bool}> */
    public const BASELINE = [
        ['GAJI', 'Basic pay', 'basic', true, true],
        ['TETAP', 'Fixed allowance', 'fixed_allowance', true, true],
        ['TIDAK', 'Variable allowance', 'variable_allowance', true, false],
        ['MAKAN', 'Meal allowance', 'meal', true, false],
        ['TRANS', 'Transport allowance', 'transport', true, false],
    ];

    public function __construct(
        private PayrollStore $store,
        private HrAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not see how pay is set.');

        return array_map(self::shape(...), $this->store->components($property, false));
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $code, string $name, string $kind, bool $taxable, bool $socialBase): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set how pay is set.');
        $clean = $this->clean($code, $name, $kind, $taxable, $socialBase);
        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $clean): void {
            if (! $this->store->addComponent($property, ['id' => $id, ...$clean], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A kind of earning with this code exists already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'pay_component.created', 'pay_component', $id, null, $clean));
        });

        return self::shape($this->store->component($property, $id) ?? throw Refusal::notFound('Kind of earning not found.'));
    }

    /** The code and the kind never change. @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, string $name, bool $taxable, bool $socialBase, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set how pay is set.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $taxable, $socialBase, $lock): void {
            $before = $this->store->component($property, strtolower($id)) ?? throw Refusal::notFound('Kind of earning not found.');
            $clean = $this->clean($before['code'], $name, $before['kind'], $taxable, $socialBase);
            unset($clean['code'], $clean['kind']);

            if (! $this->store->updateComponent($property, $before['id'], $lock, $clean, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This kind of earning changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'pay_component.changed', 'pay_component', $before['id'], array_intersect_key($before, $clean), $clean + ['code' => $before['code']]));
        });

        return self::shape($this->store->component($property, strtolower($id)) ?? throw Refusal::notFound('Kind of earning not found.'));
    }

    /** @return array<string, mixed> */
    public function setActive(PropertyId $property, string $actorId, string $id, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set how pay is set.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $active, $lock): void {
            $before = $this->store->component($property, strtolower($id)) ?? throw Refusal::notFound('Kind of earning not found.');

            if ((bool) $before['is_active'] === $active) {
                throw Refusal::stateConflict($active ? 'This kind of earning is in use already.' : 'This kind of earning is retired already.');
            }

            if (! $this->store->updateComponent($property, $before['id'], $lock, ['is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This kind of earning changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'pay_component.resumed' : 'pay_component.retired', 'pay_component', $before['id'], ['is_active' => (bool) $before['is_active']], ['is_active' => $active, 'code' => $before['code']]));
        });

        return self::shape($this->store->component($property, strtolower($id)) ?? throw Refusal::notFound('Kind of earning not found.'));
    }

    /** Writes the usual kinds when none was written yet. @return list<array<string, mixed>> */
    public function baseline(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::PAYROLL, 'This person may not set how pay is set.');

        if ($this->store->components($property, false) !== []) {
            throw Refusal::stateConflict('Kinds of earning were written already.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor): void {
            $now = $this->clock->nowUtc();

            foreach (self::BASELINE as [$code, $name, $kind, $taxable, $social]) {
                $this->store->addComponent($property, ['id' => $this->ids->next(), ...$this->clean($code, $name, $kind, $taxable, $social)], $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'pay_component.baseline', 'pay_component', $property->toString(), null, ['codes' => array_column(self::BASELINE, 0)]));
        });

        return $this->list($property, $actorId);
    }

    /** How a kind is worked out: whole, by the days there, or for each day there. */
    public static function basis(string $kind): string
    {
        return match ($kind) {
            'variable_allowance' => 'attendance',
            'meal', 'transport' => 'per_day',
            default => 'monthly',
        };
    }

    /** @return array<string, mixed> */
    public static function shape(array $c): array
    {
        return ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'kind' => $c['kind'], 'basis' => self::basis($c['kind']), 'taxable' => (bool) $c['taxable'], 'social_base' => (bool) $c['social_base'], 'active' => (bool) $c['is_active'], 'lock_version' => (int) $c['lock_version']];
    }

    /** @return array<string, mixed> */
    private function clean(string $code, string $name, string $kind, bool $taxable, bool $socialBase): array
    {
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9]{1,8}$/D', $code) !== 1) {
            throw Refusal::invalid('The code is 1 to 8 letters and digits.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 60) {
            throw Refusal::invalid('Give a name of at most 60 characters.', ['name']);
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose a kind of earning of the list.', ['kind']);
        }

        return ['code' => $code, 'name' => $name, 'kind' => $kind, 'taxable' => $taxable, 'social_base' => $socialBase];
    }
}
