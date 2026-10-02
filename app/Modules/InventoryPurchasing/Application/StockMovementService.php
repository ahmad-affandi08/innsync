<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Receipts, issues, adjustments and write-offs on the stock ledger (FR-INV-004, FR-INV-010). A receipt adds stock and an issue takes it out for a
 * department; both need `inventory.stock.post`. An adjustment or a write-off changes stock without a document behind it, so it needs a reason code
 * and the stronger privilege `inventory.stock.adjust`. An outflow may not take the balance below zero unless the category and the location allow
 * it and a person with `inventory.stock.negative` gives the reason (BR-007). The amount threshold that sends a large adjustment to a second
 * approver follows the valuation of stock (FR-INV-007): until stock has a value, every adjustment needs the privilege and a reason.
 * Other contexts (POS, kitchen, housekeeping, maintenance) post their consumption through `issue` with a source type and reference, which is
 * applied once however often it is sent.
 */
final readonly class StockMovementService
{
    public const ADJUST_PERMISSION = 'inventory.stock.adjust';

    /** Why an adjustment or a write-off is made. */
    public const ADJUST_REASONS = ['count_correction', 'found', 'damaged', 'expired', 'spoiled', 'lost', 'theft', 'other'];

    public const WRITE_OFF_REASONS = ['damaged', 'expired', 'spoiled', 'lost', 'theft', 'other'];

    public const SOURCE_TYPES = ['pos', 'kitchen', 'housekeeping', 'maintenance', 'laundry', 'manual'];

    public function __construct(
        private InventoryStore $inventory,
        private StockPoster $poster,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private PropertyContext $property,
    ) {}

    /** @return array<string, mixed> */
    public function receive(PropertyId $property, string $actorId, string $itemId, string $locationId, string $unit, string $quantity, ?string $reference, ?string $note, ?string $sourceType = null, ?string $sourceRef = null, ?IdempotencyKey $key = null): array
    {
        $this->authorize($property, $actorId, StockService::POST_PERMISSION);

        return $this->run($property, $actorId, $itemId, $locationId, 'receipt', $unit, $quantity, null, $reference, $note, $sourceType, $sourceRef, null, $key);
    }

    /**
     * @param  string  $department  the department that used the stock
     * @return array<string, mixed>
     */
    public function issue(PropertyId $property, string $actorId, string $itemId, string $locationId, string $unit, string $quantity, string $department, ?string $reference, ?string $note, ?string $negativeReason = null, ?string $sourceType = null, ?string $sourceRef = null, ?IdempotencyKey $key = null): array
    {
        $this->authorize($property, $actorId, StockService::POST_PERMISSION);

        if (! in_array($department, InventoryCatalogService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose the department that used the stock.', ['reason_code']);
        }

        return $this->run($property, $actorId, $itemId, $locationId, 'issue', $unit, $quantity, $department, $reference, $note, $sourceType, $sourceRef, $negativeReason, $key);
    }

    /** @return array<string, mixed> */
    public function adjust(PropertyId $property, string $actorId, bool $increase, string $itemId, string $locationId, string $unit, string $quantity, string $reasonCode, ?string $reference, ?string $note, ?string $negativeReason = null, ?IdempotencyKey $key = null): array
    {
        $this->authorize($property, $actorId, self::ADJUST_PERMISSION);
        $this->reason($reasonCode, self::ADJUST_REASONS, $note);

        return $this->run($property, $actorId, $itemId, $locationId, $increase ? 'adjustment_in' : 'adjustment_out', $unit, $quantity, $reasonCode, $reference, $note, null, null, $negativeReason, $key);
    }

    /** @return array<string, mixed> */
    public function writeOff(PropertyId $property, string $actorId, string $itemId, string $locationId, string $unit, string $quantity, string $reasonCode, ?string $reference, ?string $note, ?string $negativeReason = null, ?IdempotencyKey $key = null): array
    {
        $this->authorize($property, $actorId, self::ADJUST_PERMISSION);
        $this->reason($reasonCode, self::WRITE_OFF_REASONS, $note);

        return $this->run($property, $actorId, $itemId, $locationId, 'write_off', $unit, $quantity, $reasonCode, $reference, $note, null, null, $negativeReason, $key);
    }

    /** @param list<string> $allowed */
    private function reason(string $code, array $allowed, ?string $note): void
    {
        if (! in_array($code, $allowed, true)) {
            throw Refusal::invalid('Choose the reason.', ['reason_code']);
        }

        if ($code === 'other' && ($note === null || trim($note) === '')) {
            throw Refusal::invalid('Say what happened when the reason is "other".', ['note']);
        }
    }

    /** @return array<string, mixed> */
    private function run(PropertyId $property, string $actorId, string $itemId, string $locationId, string $kind, string $unit, string $quantity, ?string $reasonCode, ?string $reference, ?string $note, ?string $sourceType, ?string $sourceRef, ?string $negativeReason, ?IdempotencyKey $key): array
    {
        $qty = StockQuantity::parse($quantity);

        if ($qty === null || $qty < 1 || $qty > StockQuantity::MAX_MILLI) {
            throw Refusal::invalid('Give the quantity as a number above zero, with at most three decimals.', ['quantity']);
        }

        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($reference !== null && mb_strlen($reference) > 40) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('The reference is at most 40 characters and the note at most 200.', ['reference']);
        }

        if (($sourceType === null) !== ($sourceRef === null) || ($sourceType !== null && (! in_array($sourceType, self::SOURCE_TYPES, true) || $sourceRef === '' || mb_strlen((string) $sourceRef) > 64))) {
            throw Refusal::invalid('A source needs a known type and a reference of at most 64 characters.', ['source_ref']);
        }

        $item = $this->inventory->item($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');
        $location = $this->inventory->location($property, strtolower($locationId)) ?? throw Refusal::notFound('Location not found.');

        if (! (bool) $item['is_active'] || ! (bool) $location['is_active']) {
            throw Refusal::stateConflict('Stock cannot be posted to an inactive item or location.');
        }

        $unit = strtoupper(trim($unit));
        $post = fn (): array => $this->poster->post($property, $actorId, $item, $location, $kind, $unit, $qty, $reasonCode, $reference, $note, $sourceType, $sourceRef, null, $negativeReason, true);

        if ($key === null) {
            $result = $this->transactions->run($post);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'inventory.stock.move', ['kind' => $kind, 'item' => $item['id'], 'location' => $location['id'], 'unit' => $unit, 'qty' => $qty, 'reason' => $reasonCode, 'reference' => $reference, 'note' => $note, 'negative' => $negativeReason], strtolower($actorId)),
                fn (): array => $post()['movement'],
            );
            $result = ['movement' => $once->payload, 'replayed' => $once->replayed];
        }

        $balance = $this->inventory->balanceOf($property, $item['id'], $location['id']);

        return [...$result['movement'], 'balance_milli' => $balance, 'replayed' => $result['replayed']];
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, $permission, $property)) {
            throw Refusal::forbidden('This person may not post this kind of stock movement.');
        }
    }
}
