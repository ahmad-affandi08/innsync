<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The register of fixed assets and their straight-line depreciation (docs/OPERATIONS/FIXED-ASSETS.md). An asset has a cost, a value at the end of its life, the months it lasts and the day it was acquired. A month is
 * depreciated once it has ended, for every asset at once, and the entries are never changed; an asset that leaves is recorded with how it left and what it brought in, and stops depreciating from that month.
 * Buying an asset is booked as a supplier invoice to the account of the fixed asset (set that account's code in the accounting export); this register only carries the depreciation and the disposal.
 */
final readonly class FixedAssetService
{
    public const CATEGORIES = ['building', 'furniture', 'equipment', 'vehicle', 'it', 'other'];

    public const KINDS = ['sale', 'scrap', 'lost'];

    public const METHODS = ['cash', 'transfer'];

    public function __construct(
        private FixedAssetStore $store,
        private FinanceAccess $access,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->view($property, $actorId);
        $through = $this->closedThrough($property);
        $assets = array_map(fn (array $a): array => $this->shape($a, $through), $this->store->all($property));

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $this->businessDate->current($property)->toString(), 'through' => $through, 'categories' => self::CATEGORIES, 'baseline_life' => Depreciation::BASELINE_LIFE,
            'kinds' => self::KINDS, 'methods' => self::METHODS, 'assets' => $assets, 'pending_months' => array_sum(array_column($assets, 'pending_months')),
            'totals' => ['cost_minor' => array_sum(array_column(array_filter($assets, static fn (array $a): bool => $a['status'] === 'active'), 'cost_minor')), 'book_minor' => array_sum(array_column(array_filter($assets, static fn (array $a): bool => $a['status'] === 'active'), 'book_minor'))],
            'may_manage' => $this->access->may($property, $actorId, FinanceAccess::ASSET_MANAGE),
        ];
    }

    /** @return array<string, mixed> */
    public function add(PropertyId $property, string $actorId, string $code, string $name, string $category, string $acquiredOn, int $costMinor, int $salvageMinor, int $lifeMonths, ?string $notes): array
    {
        $this->manage($property, $actorId);
        $code = strtoupper(trim($code));
        $name = trim($name);
        $notes = $notes === null || trim($notes) === '' ? null : trim($notes);
        $today = $this->businessDate->current($property)->toString();

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,19}$/', $code) !== 1) {
            throw Refusal::invalid('The code is 1 to 20 letters, digits, dot, dash or underscore.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw Refusal::invalid('Give the name, at most 120 characters.', ['name']);
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw Refusal::invalid('Choose a category.', ['category']);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquiredOn) !== 1 || $acquiredOn > $today || $acquiredOn < '2000-01-01') {
            throw Refusal::invalid('The day acquired is today or earlier.', ['acquired_on']);
        }

        if ($costMinor < 1 || $costMinor > 900_000_000_000_00 || $salvageMinor < 0 || $salvageMinor >= $costMinor) {
            throw Refusal::invalid('The cost is above zero, and the value at the end of its life is less than the cost.', ['cost_minor', 'salvage_minor']);
        }

        if ($lifeMonths < 1 || $lifeMonths > 600) {
            throw Refusal::invalid('The life is 1 to 600 months.', ['life_months']);
        }

        if ($notes !== null && mb_strlen($notes) > 300) {
            throw Refusal::invalid('The note is at most 300 characters.', ['notes']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $category, $acquiredOn, $costMinor, $salvageMinor, $lifeMonths, $notes): void {
            if (! $this->store->add($property, ['id' => $id, 'code' => $code, 'name' => $name, 'category' => $category, 'acquired_on' => $acquiredOn, 'cost_minor' => $costMinor, 'salvage_minor' => $salvageMinor, 'life_months' => $lifeMonths, 'notes' => $notes, 'created_by' => strtolower($actorId)], $this->clock->nowUtc())) {
                throw Refusal::invalid('This code is used already.', ['code']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fixed_asset.added', 'fixed_asset', $id, null, ['code' => $code, 'category' => $category, 'cost_minor' => $costMinor, 'salvage_minor' => $salvageMinor, 'life_months' => $lifeMonths, 'acquired_on' => $acquiredOn]));
        });

        return $this->overview($property, $actorId);
    }

    /** Depreciates every month up to `$through` (a month that has ended) for every asset, once. @return array{months: int, amount_minor: int} */
    public function run(PropertyId $property, string $actorId, string $through): array
    {
        $this->manage($property, $actorId);
        $limit = $this->closedThrough($property);

        if (preg_match('/^\d{4}-\d{2}$/', $through) !== 1 || $through > $limit) {
            throw Refusal::invalid('A month is depreciated after it has ended: choose '.$limit.' or earlier.', ['through']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $through): array {
            $months = 0;
            $total = 0;

            foreach ($this->store->all($property) as $a) {
                $due = Depreciation::due((int) $a['cost_minor'], (int) $a['salvage_minor'], (int) $a['life_months'], substr((string) $a['acquired_on'], 0, 10), $a['disposed_on'] === null ? null : substr((string) $a['disposed_on'], 0, 10), (int) $a['entries'], (int) $a['accumulated_minor'], $through);

                if ($due === []) {
                    continue;
                }

                $this->store->addEntries($property, $a['id'], $due, strtolower($actorId), $this->clock->nowUtc());
                $months += count($due);
                $total += array_sum(array_column($due, 'amount_minor'));
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fixed_asset.depreciated', 'fixed_asset_run', $through, null, ['through' => $through, 'months' => $months, 'amount_minor' => $total]));

            return ['months' => $months, 'amount_minor' => $total];
        });
    }

    /** An asset leaves: sold, scrapped or lost. The depreciation up to the month before must be booked first. @return array<string, mixed> */
    public function dispose(PropertyId $property, string $actorId, string $id, string $kind, string $on, int $proceedsMinor, ?string $method, string $reason, int $lock): array
    {
        $this->manage($property, $actorId);
        $reason = trim($reason);
        $today = $this->businessDate->current($property)->toString();

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose sold, scrapped or lost.', ['kind']);
        }

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        if ($proceedsMinor < 0 || ($kind !== 'sale' && $proceedsMinor !== 0)) {
            throw Refusal::invalid('Only a sale brings money in.', ['proceeds_minor']);
        }

        if ($proceedsMinor > 0 && ! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Say whether the buyer paid in cash or by transfer.', ['method']);
        }

        $a = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Asset not found.');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) !== 1 || $on > $today || $on < substr((string) $a['acquired_on'], 0, 10)) {
            throw Refusal::invalid('The day it left is between the day it was acquired and today.', ['disposed_on']);
        }

        $this->transactions->run(function () use ($property, $actorId, $a, $kind, $on, $proceedsMinor, $method, $reason, $lock): void {
            $row = collect($this->store->all($property))->firstWhere('id', $a['id']);
            $owed = Depreciation::due((int) $a['cost_minor'], (int) $a['salvage_minor'], (int) $a['life_months'], substr((string) $a['acquired_on'], 0, 10), $on, (int) $row['entries'], (int) $row['accumulated_minor'], Depreciation::plus(Depreciation::period($on), -1));

            if ($owed !== []) {
                throw Refusal::stateConflict('Run the depreciation through '.$owed[array_key_last($owed)]['period'].' before recording that this asset left.');
            }

            if (! $this->store->dispose($property, $a['id'], $lock, ['disposed_on' => $on, 'disposal_kind' => $kind, 'disposal_proceeds_minor' => $proceedsMinor, 'disposal_method' => $proceedsMinor > 0 ? $method : null, 'disposal_reason' => $reason], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This asset changed after you opened it, or has left already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'fixed_asset.disposed', 'fixed_asset', $a['id'], ['status' => 'active'], ['status' => 'disposed', 'kind' => $kind, 'on' => $on, 'proceeds_minor' => $proceedsMinor, 'book_minor' => (int) $a['cost_minor'] - (int) $row['accumulated_minor']], $reason));
        });

        return $this->overview($property, $actorId);
    }

    /** The last month that has ended: the month before the one the business date is in. */
    private function closedThrough(PropertyId $property): string
    {
        return Depreciation::plus(Depreciation::period($this->businessDate->current($property)->toString()), -1);
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function shape(array $a, string $through): array
    {
        $cost = (int) $a['cost_minor'];
        $accumulated = (int) $a['accumulated_minor'];
        $due = Depreciation::due($cost, (int) $a['salvage_minor'], (int) $a['life_months'], substr((string) $a['acquired_on'], 0, 10), $a['disposed_on'] === null ? null : substr((string) $a['disposed_on'], 0, 10), (int) $a['entries'], $accumulated, $through);

        return [
            'id' => $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'category' => $a['category'], 'acquired_on' => substr((string) $a['acquired_on'], 0, 10), 'cost_minor' => $cost, 'salvage_minor' => (int) $a['salvage_minor'], 'life_months' => (int) $a['life_months'],
            'monthly_minor' => intdiv($cost - (int) $a['salvage_minor'], (int) $a['life_months']), 'accumulated_minor' => $accumulated, 'book_minor' => $cost - $accumulated, 'depreciated_through' => $a['last_period'], 'pending_months' => count($due),
            'status' => $a['status'], 'disposed_on' => $a['disposed_on'] === null ? null : substr((string) $a['disposed_on'], 0, 10), 'disposal_kind' => $a['disposal_kind'], 'disposal_proceeds_minor' => $a['disposal_proceeds_minor'] === null ? null : (int) $a['disposal_proceeds_minor'],
            'lock_version' => (int) $a['lock_version'], 'notes' => $a['notes'],
        ];
    }

    private function view(PropertyId $property, string $actorId): void
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, FinanceAccess::ASSET_VIEW) && ! $this->access->may($property, $actorId, FinanceAccess::ASSET_MANAGE)) {
            throw Refusal::forbidden('This person may not see fixed assets.');
        }
    }

    private function manage(PropertyId $property, string $actorId): void
    {
        $this->access->require($property, $actorId, FinanceAccess::ASSET_MANAGE, 'This person may not manage fixed assets.');
    }
}
