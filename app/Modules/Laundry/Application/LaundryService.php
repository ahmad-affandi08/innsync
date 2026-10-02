<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\Laundry\Domain\LaundryLine;
use App\Modules\Laundry\Domain\LaundryOrder;
use App\Modules\Laundry\Domain\LaundryRuleViolation;
use App\Modules\Laundry\Domain\LaundryStatus;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\CalendarDate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Guest laundry (FR-HK-020 to FR-HK-024, FR-LDY-001 to FR-LDY-004, FR-LDY-011, FR-LDY-012). Housekeeping hands a bag over for a
 * room that has a guest in it, listing the items; the laundry counts them before any work starts and records any difference;
 * the order moves wash, dry, iron, ready in order; becoming ready charges the folio from the prices copied at hand-over and
 * the counted quantities; housekeeping delivers it to the room against a receipt.
 *
 * Prices are property data (PRD Q-10): nothing is priced until a price list exists, and the charge fails closed when the
 * service charge and tax scheme for `laundry` is not configured. The charge is posted once per order (BR-005).
 */
final readonly class LaundryService implements LaundryLiability
{
    public const INTAKE_PERMISSION = 'laundry.order.intake';

    public const PROCESS_PERMISSION = 'laundry.order.process';

    public const DELIVER_PERMISSION = 'laundry.order.deliver';

    public const CANCEL_PERMISSION = 'laundry.order.cancel';

    public const PRICES_PERMISSION = 'laundry.prices.manage';

    public const VIEW_PERMISSION = 'laundry.view';

    public const CHARGE_SCOPE = 'laundry';

    public const CHARGE_CODE = 'LAUNDRY';

    public function __construct(
        private LaundryRepository $repository,
        private GuestCharging $charging,
        private RoomCatalogReader $rooms,
        private PropertyTimeZoneReader $zones,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private IdempotentExecutor $executor,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    public function activeOrdersOfStay(PropertyId $property, string $stayId): int
    {
        return $this->repository->activeOrdersOfStay($property, strtolower($stayId));
    }

    // ---- price list ----

    /** @return list<array{id: string, code: string, name: string, unit_price_minor: int, is_active: bool, lock_version: int}> */
    public function priceList(PropertyId $property, string $actorId, bool $activeOnly = false): array
    {
        $this->authorizeAny($property, $actorId, [self::VIEW_PERMISSION, self::INTAKE_PERMISSION, self::PROCESS_PERMISSION, self::PRICES_PERMISSION]);

        return $this->repository->priceItems($property, $activeOnly);
    }

    /** @return array<string, mixed> */
    public function addPriceItem(PropertyId $property, string $actorId, string $code, string $name, int $unitPriceMinor, string $reason): array
    {
        $this->authorize($property, $actorId, self::PRICES_PERMISSION);
        $this->assertReason($reason);
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9_-]{2,20}$/D', $code) !== 1 || $name === '' || mb_strlen($name) > 80 || $unitPriceMinor < 0 || $unitPriceMinor > 100_000_000_00) {
            throw Refusal::invalid('Give a code of 2 to 20 letters or digits, a name and a price of zero or more.', ['code', 'name', 'unit_price_minor']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $code, $name, $unitPriceMinor, $reason): array {
            $id = $this->ids->next();

            if (! $this->repository->addPriceItem($property, $id, $code, $name, $unitPriceMinor, $this->clock->nowUtc())) {
                throw Refusal::invalid('This code is already used.', ['code']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'laundry.price.added', 'laundry_price_item', $id, null, ['code' => $code, 'name' => $name, 'unit_price_minor' => $unitPriceMinor], trim($reason)));

            return $this->repository->findPriceItem($property, $id) ?? throw Refusal::notFound('Item not found.');
        });
    }

    /**
     * Changing a price never changes an order already made: orders keep the prices copied when they were handed over.
     *
     * @return array<string, mixed>
     */
    public function updatePriceItem(PropertyId $property, string $actorId, string $id, string $name, int $unitPriceMinor, bool $active, int $expectedLockVersion, string $reason): array
    {
        $this->authorize($property, $actorId, self::PRICES_PERMISSION);
        $this->assertReason($reason);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80 || $unitPriceMinor < 0 || $unitPriceMinor > 100_000_000_00) {
            throw Refusal::invalid('Give a name and a price of zero or more.', ['name', 'unit_price_minor']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $id, $name, $unitPriceMinor, $active, $expectedLockVersion, $reason): array {
            $before = $this->repository->findPriceItem($property, strtolower($id)) ?? throw Refusal::notFound('Item not found.');

            if (! $this->repository->updatePriceItem($property, $before['id'], $name, $unitPriceMinor, $active, $expectedLockVersion, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed after you opened it.');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), strtolower($actorId), 'laundry.price.changed', 'laundry_price_item', $before['id'],
                ['name' => $before['name'], 'unit_price_minor' => $before['unit_price_minor'], 'is_active' => $before['is_active']],
                ['name' => $name, 'unit_price_minor' => $unitPriceMinor, 'is_active' => $active], trim($reason),
            ));

            return $this->repository->findPriceItem($property, $before['id']) ?? throw Refusal::notFound('Item not found.');
        });
    }

    // ---- special treatments and express (FR-LDY-005) ----

    /** @return list<array{id: string, code: string, name: string, kind: string, pricing: string, value: int, is_active: bool, lock_version: int}> */
    public function treatments(PropertyId $property, string $actorId, bool $activeOnly = false): array
    {
        $this->authorizeAny($property, $actorId, [self::VIEW_PERMISSION, self::INTAKE_PERMISSION, self::PROCESS_PERMISSION, self::PRICES_PERMISSION]);

        return $this->repository->treatments($property, $activeOnly);
    }

    /**
     * Adds a special treatment (`service`, chosen per line) or the express service (`express`, added to every line of an express
     * order; one at a time). The extra is per piece: `percent` is a share of the item's price in basis points, `fixed` an amount.
     *
     * @return array<string, mixed>
     */
    public function addTreatment(PropertyId $property, string $actorId, string $code, string $name, string $kind, string $pricing, int $value, string $reason): array
    {
        $this->authorize($property, $actorId, self::PRICES_PERMISSION);
        $this->assertReason($reason);
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9_-]{2,20}$/D', $code) !== 1 || $name === '' || mb_strlen($name) > 80 || ! in_array($kind, ['service', 'express'], true)) {
            throw Refusal::invalid('Give a code of 2 to 20 letters or digits, a name, and service or express.', ['code', 'name', 'kind']);
        }

        $this->assertTreatmentValue($pricing, $value);

        return $this->transactions->run(function () use ($property, $actorId, $code, $name, $kind, $pricing, $value, $reason): array {
            $id = $this->ids->next();
            $result = $this->repository->addTreatment($property, $id, $code, $name, $kind, $pricing, $value, $this->clock->nowUtc());

            if ($result === 'code_used') {
                throw Refusal::invalid('This code is already used.', ['code']);
            }

            if ($result === 'express_exists') {
                throw Refusal::stateConflict('There is already an express service in use: stop it before adding another.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'laundry.treatment.added', 'laundry_treatment', $id, null, ['code' => $code, 'name' => $name, 'kind' => $kind, 'pricing' => $pricing, 'value' => $value], trim($reason)));

            return $this->repository->findTreatment($property, $id) ?? throw Refusal::notFound('Treatment not found.');
        });
    }

    /**
     * Changing a rate never changes an order already made: orders keep what they were charged at hand-over.
     *
     * @return array<string, mixed>
     */
    public function updateTreatment(PropertyId $property, string $actorId, string $id, string $name, string $pricing, int $value, bool $active, int $expectedLockVersion, string $reason): array
    {
        $this->authorize($property, $actorId, self::PRICES_PERMISSION);
        $this->assertReason($reason);
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a name.', ['name']);
        }

        $this->assertTreatmentValue($pricing, $value);

        return $this->transactions->run(function () use ($property, $actorId, $id, $name, $pricing, $value, $active, $expectedLockVersion, $reason): array {
            $before = $this->repository->findTreatment($property, strtolower($id)) ?? throw Refusal::notFound('Treatment not found.');
            $result = $this->repository->updateTreatment($property, $before['id'], $name, $pricing, $value, $active, $expectedLockVersion, $this->clock->nowUtc());

            if ($result === 'stale') {
                throw Refusal::stateConflict('This treatment changed after you opened it.');
            }

            if ($result === 'express_exists') {
                throw Refusal::stateConflict('There is already an express service in use: stop it before using another.');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), strtolower($actorId), 'laundry.treatment.changed', 'laundry_treatment', $before['id'],
                ['name' => $before['name'], 'pricing' => $before['pricing'], 'value' => $before['value'], 'is_active' => $before['is_active']],
                ['name' => $name, 'pricing' => $pricing, 'value' => $value, 'is_active' => $active], trim($reason),
            ));

            return $this->repository->findTreatment($property, $before['id']) ?? throw Refusal::notFound('Treatment not found.');
        });
    }

    private function assertTreatmentValue(string $pricing, int $value): void
    {
        if (! in_array($pricing, ['percent', 'fixed'], true) || $value < 0 || ($pricing === 'percent' ? $value > 100_000 : $value > 100_000_000_00)) {
            throw Refusal::invalid('Choose a percentage (up to 1000 percent) or a fixed amount per piece, zero or more.', ['pricing', 'value']);
        }
    }

    /** The extra for one piece of an item priced `$unitMinor`: a percentage is rounded half up to the smallest unit. */
    private static function extraFor(string $pricing, int $value, int $unitMinor): int
    {
        return $pricing === 'percent' ? intdiv($unitMinor * $value + 5_000, 10_000) : $value;
    }

    // ---- housekeeping: hand a bag over ----

    /**
     * Rooms with a guest in them and the price list, for the intake screen.
     *
     * @return array{rooms: list<array{id: string, number: string}>, items: list<array<string, mixed>>, treatments: list<array<string, mixed>>, business_date: string, zone: string}
     */
    public function intakeLookups(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::INTAKE_PERMISSION);
        $rooms = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            if ($this->charging->inHouseStayOfRoom($property, $room->id) !== null) {
                $rooms[] = ['id' => $room->id, 'number' => $room->number];
            }
        }

        usort($rooms, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));

        return [
            'rooms' => $rooms,
            'items' => $this->repository->priceItems($property, true),
            'treatments' => $this->repository->treatments($property, true),
            'business_date' => $this->businessDate->current($property)->toString(),
            'zone' => ($this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.'))->identifier(),
        ];
    }

    /** @return array<string, mixed> */
    public function intake(PropertyId $property, string $actorId, LaundryRequest $request, IdempotencyKey $key): array
    {
        $this->authorize($property, $actorId, self::INTAKE_PERMISSION);

        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'laundry.intake', $request->fingerprint(), strtolower($actorId)),
            fn (): array => ['id' => $this->open($property, strtolower($actorId), $request)->id],
        );

        return $this->view($property, $actorId, (string) $result->payload['id']);
    }

    // ---- laundry: count and process ----

    /**
     * The laundry counts the bag against the list. `$counted` has a quantity for every line; a difference needs a note.
     *
     * @param  array<string, int>  $counted  counted quantity by line id
     * @return array<string, mixed>
     */
    public function receive(PropertyId $property, string $actorId, string $orderId, array $counted, ?string $note, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::PROCESS_PERMISSION);

        return $this->change($property, $actorId, $orderId, $expectedLockVersion, 'laundry.order.received', static fn (LaundryOrder $o): LaundryOrder => $o->receive($counted, $note), ['processed_by' => strtolower($actorId)]);
    }

    /** Washing, then drying, then ironing. @return array<string, mixed> */
    public function advance(PropertyId $property, string $actorId, string $orderId, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::PROCESS_PERMISSION);

        return $this->change($property, $actorId, $orderId, $expectedLockVersion, 'laundry.order.advanced', static fn (LaundryOrder $o): LaundryOrder => $o->advance(), ['processed_by' => strtolower($actorId)]);
    }

    /**
     * Ironed laundry is ready: it is charged to the guest's folio (counted quantities at the prices copied at hand-over, plus
     * service charge and tax) and housekeeping is told to collect it. If the charge cannot be posted the order stays as it is.
     *
     * @return array<string, mixed>
     */
    public function markReady(PropertyId $property, string $actorId, string $orderId, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::PROCESS_PERMISSION);
        $actor = strtolower($actorId);

        return $this->transactions->run(function () use ($property, $actor, $orderId, $expectedLockVersion): array {
            $before = $this->repository->findOrder($property, strtolower($orderId)) ?? throw Refusal::notFound('Order not found.');
            $after = $this->transition(static fn () => $before->markReady());
            $this->assertVersion($before, $expectedLockVersion);
            $total = $after->billableMinor();
            $posting = $total > 0
                ? $this->charging->charge($property, $actor, $before->reservationId, self::CHARGE_SCOPE, self::CHARGE_CODE, 'Laundry '.$before->number, $total, 'laundry', $before->id)
                : null;
            $now = $this->clock->nowUtc();
            $saved = $this->save($property, $after, $expectedLockVersion, ['processed_by' => $actor, 'ready_at' => $now, 'currency_code' => $posting['currency'] ?? null], $now);
            $this->repository->logStatus($property, $before->id, $before->status, LaundryStatus::Ready, $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'laundry.order.ready', 'laundry_order', $before->id, ['status' => $before->status->value], ['status' => 'ready', 'billable_minor' => $total, 'posting_id' => $posting['posting_id'] ?? null]));
            $this->announce($property, 'laundry.order.ready', $saved, $actor);

            return $this->describe($saved, $this->repository->history($property, $saved->id));
        });
    }

    // ---- housekeeping: deliver ----

    /** Housekeeping brings the laundry to the room and records who received it. @return array<string, mixed> */
    public function deliver(PropertyId $property, string $actorId, string $orderId, string $receipt, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::DELIVER_PERMISSION);
        $receipt = trim($receipt);

        if ($receipt === '' || mb_strlen($receipt) > 200) {
            throw Refusal::invalid('Record who received the laundry, at most 200 characters.', ['receipt']);
        }

        $now = $this->clock->nowUtc();

        return $this->change($property, $actorId, $orderId, $expectedLockVersion, 'laundry.order.delivered', static fn (LaundryOrder $o): LaundryOrder => $o->deliver($now), [
            'delivered_by' => strtolower($actorId), 'receipt_note' => $receipt,
        ]);
    }

    /** Stops an order before any work has begun. @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $orderId, string $reason, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::CANCEL_PERMISSION);
        $this->assertReason($reason);

        return $this->change($property, $actorId, $orderId, $expectedLockVersion, 'laundry.order.cancelled', static fn (LaundryOrder $o): LaundryOrder => $o->cancel(), ['cancel_reason' => trim($reason)], trim($reason));
    }

    // ---- reads ----

    /**
     * Active orders for the laundry's work list: express first, then the earliest promise, with overdue ones flagged (FR-LDY-011).
     *
     * @return list<array<string, mixed>>
     */
    public function queue(PropertyId $property, string $actorId): array
    {
        $this->authorizeAny($property, $actorId, [self::VIEW_PERMISSION, self::PROCESS_PERMISSION, self::DELIVER_PERMISSION, self::INTAKE_PERMISSION]);
        $now = $this->clock->nowUtc();

        return array_map(fn (LaundryOrder $o): array => $this->summary($o, $now), $this->repository->orders($property, [LaundryStatus::Sent, LaundryStatus::Received, LaundryStatus::Washing, LaundryStatus::Drying, LaundryStatus::Ironing, LaundryStatus::Ready], 200));
    }

    /** @return array<string, mixed> */
    public function view(PropertyId $property, string $actorId, string $orderId): array
    {
        $this->authorizeAny($property, $actorId, [self::VIEW_PERMISSION, self::PROCESS_PERMISSION, self::DELIVER_PERMISSION, self::INTAKE_PERMISSION]);
        $order = $this->repository->findOrder($property, strtolower($orderId)) ?? throw Refusal::notFound('Order not found.');

        return $this->describe($order, $this->repository->history($property, $order->id));
    }

    /** ISO 4217 code that prices and charges are shown in. */
    public function currency(PropertyId $property): string
    {
        $this->assertProperty($property);

        return $this->currencies->currencyOf($property);
    }

    /** @return array{intake: bool, process: bool, deliver: bool, cancel: bool, prices: bool} what this person may do, so a screen shows only what works */
    public function abilities(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);

        return [
            'intake' => $this->permissions->allowsInProperty($actorId, self::INTAKE_PERMISSION, $property),
            'process' => $this->permissions->allowsInProperty($actorId, self::PROCESS_PERMISSION, $property),
            'deliver' => $this->permissions->allowsInProperty($actorId, self::DELIVER_PERMISSION, $property),
            'cancel' => $this->permissions->allowsInProperty($actorId, self::CANCEL_PERMISSION, $property),
            'prices' => $this->permissions->allowsInProperty($actorId, self::PRICES_PERMISSION, $property),
        ];
    }

    // ---- internals ----

    private function open(PropertyId $property, string $actor, LaundryRequest $request): LaundryOrder
    {
        $stay = $this->charging->inHouseStayOfRoom($property, strtolower($request->roomId)) ?? throw Refusal::invalid('Choose a room that has a guest in it.', ['room_id']);
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');

        try {
            $promised = $zone->utcAt(CalendarDate::fromString($request->promisedDate), $request->promisedTime);
        } catch (InvalidArgumentException|\DomainException) {
            throw Refusal::invalid('Give the promised date and time.', ['promised_date', 'promised_time']);
        }

        $now = $this->clock->nowUtc();

        if ($promised <= $now) {
            throw Refusal::invalid('The promised time must be in the future.', ['promised_date', 'promised_time']);
        }

        $lines = [];
        $seen = [];
        $express = $request->express ? array_values(array_filter($this->repository->treatments($property, true), static fn (array $t): bool => $t['kind'] === 'express'))[0] ?? null : null;

        foreach ($request->lines as $line) {
            $item = $this->repository->findPriceItem($property, strtolower((string) ($line['price_item_id'] ?? '')));

            if ($item === null || ! $item['is_active']) {
                throw Refusal::invalid('Choose items from the price list.', ['lines']);
            }

            $brand = isset($line['brand']) && trim((string) $line['brand']) !== '' ? trim((string) $line['brand']) : null;
            $note = isset($line['condition_note']) && trim((string) $line['condition_note']) !== '' ? trim((string) $line['condition_note']) : null;
            $treatment = null;

            if (isset($line['treatment_id']) && (string) $line['treatment_id'] !== '') {
                $treatment = $this->repository->findTreatment($property, strtolower((string) $line['treatment_id']));

                if ($treatment === null || ! $treatment['is_active'] || $treatment['kind'] !== 'service') {
                    throw Refusal::invalid('Choose a treatment from the list.', ['lines']);
                }
            }

            $signature = $item['id'].'|'.$brand.'|'.$note.'|'.($treatment['id'] ?? '');

            if (isset($seen[$signature])) {
                throw Refusal::invalid('List each kind of item once; add the quantity instead.', ['lines']);
            }

            $seen[$signature] = true;

            try {
                $lines[] = new LaundryLine($this->ids->next(), $item['id'], $item['name'], $brand, (int) ($line['quantity'] ?? 0), $item['unit_price_minor'], $note, null, $treatment['name'] ?? null, $treatment === null ? 0 : self::extraFor($treatment['pricing'], $treatment['value'], $item['unit_price_minor']), $express === null ? 0 : self::extraFor($express['pricing'], $express['value'], $item['unit_price_minor']));
            } catch (LaundryRuleViolation $e) {
                throw Refusal::invalid($e->getMessage(), ['lines']);
            }
        }

        try {
            $order = new LaundryOrder(
                $this->ids->next(), $this->numbers->next($property, 'LDY'), trim($request->barcode), strtolower($request->roomId), $stay['stay_id'], $stay['reservation_id'], LaundryStatus::Sent,
                $request->express, $this->businessDate->current($property)->toString(), $promised,
                $request->notes === null || trim($request->notes) === '' ? null : trim($request->notes), false, null, null, null, 0, $lines,
            );
        } catch (LaundryRuleViolation $e) {
            throw Refusal::invalid($e->getMessage(), $e->field === null ? [] : [$e->field]);
        }

        if ($this->repository->addOrder($property, $order, $actor, $now) === LaundryRepository::BAG_BUSY) {
            throw Refusal::stateConflict('This bag tag is already on an order that has not been delivered.');
        }

        $this->repository->logStatus($property, $order->id, null, LaundryStatus::Sent, $actor, $now);
        $this->audit->record(new AuditEntry($property->toString(), $actor, 'laundry.order.sent', 'laundry_order', $order->id, null, ['number' => $order->number, 'room_id' => $order->roomId, 'express' => $order->express, 'items' => array_sum(array_map(static fn (LaundryLine $l): int => $l->quantity, $lines))]));
        $this->announce($property, 'laundry.order.sent', $order, $actor);

        return $order;
    }

    /**
     * @param  \Closure(LaundryOrder): LaundryOrder  $change
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function change(PropertyId $property, string $actorId, string $orderId, int $expectedLockVersion, string $action, \Closure $change, array $extra, ?string $reason = null): array
    {
        $actor = strtolower($actorId);

        return $this->transactions->run(function () use ($property, $actor, $orderId, $expectedLockVersion, $action, $change, $extra, $reason): array {
            $before = $this->repository->findOrder($property, strtolower($orderId)) ?? throw Refusal::notFound('Order not found.');
            $this->assertVersion($before, $expectedLockVersion);
            $after = $this->transition(static fn () => $change($before));
            $now = $this->clock->nowUtc();
            $saved = $this->save($property, $after, $expectedLockVersion, $extra, $now);
            $this->repository->logStatus($property, $before->id, $before->status, $saved->status, $actor, $now);
            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, $action, 'laundry_order', $before->id, ['status' => $before->status->value],
                ['status' => $saved->status->value, 'discrepancy' => $saved->hasDiscrepancy], $reason ?? $saved->discrepancyNote,
            ));
            $this->announce($property, 'laundry.order.'.$saved->status->value, $saved, $actor);

            return $this->describe($saved, $this->repository->history($property, $saved->id));
        });
    }

    /** @param \Closure(): LaundryOrder $operation */
    private function transition(\Closure $operation): LaundryOrder
    {
        try {
            return $operation();
        } catch (LaundryRuleViolation $e) {
            throw $e->reasonCode === LaundryRuleViolation::INVALID
                ? Refusal::invalid($e->getMessage(), $e->field === null ? [] : [$e->field])
                : Refusal::stateConflict($e->getMessage());
        }
    }

    private function assertVersion(LaundryOrder $order, int $expected): void
    {
        if ($order->lockVersion !== $expected) {
            throw Refusal::stateConflict('This order changed after you opened it.');
        }
    }

    /** @param array<string, mixed> $extra */
    private function save(PropertyId $property, LaundryOrder $order, int $expectedLockVersion, array $extra, DateTimeImmutable $now): LaundryOrder
    {
        if (! $this->repository->saveOrder($property, $order, $expectedLockVersion, $extra, $now)) {
            throw Refusal::stateConflict('This order changed after you opened it.');
        }

        return $this->repository->findOrder($property, $order->id) ?? $order;
    }

    private function announce(PropertyId $property, string $type, LaundryOrder $order, string $actorId): void
    {
        $this->outbox->publish(new OutboxEvent($property, $type, $order->id, 1, [
            'order_id' => $order->id, 'number' => $order->number, 'room_id' => $order->roomId, 'status' => $order->status->value, 'actor_id' => $actorId,
        ]));
    }

    /** @return array<string, mixed> */
    private function summary(LaundryOrder $o, DateTimeImmutable $now): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'barcode' => $o->barcode,
            'room_id' => $o->roomId,
            'room_number' => $this->rooms->room($this->property->current(), $o->roomId)?->number,
            'status' => $o->status->value,
            'express' => $o->express,
            'promised_at' => $o->promisedAt->format('Y-m-d\TH:i:s\Z'),
            'overdue' => $o->isOverdue($now),
            'items' => array_sum(array_map(static fn (LaundryLine $l): int => $l->billableQuantity(), $o->lines)),
            'has_discrepancy' => $o->hasDiscrepancy,
            'lock_version' => $o->lockVersion,
        ];
    }

    /**
     * @param  list<array{from: ?string, to: string, actor_id: string, occurred_at: string}>  $history
     * @return array<string, mixed>
     */
    private function describe(LaundryOrder $o, array $history): array
    {
        return [
            ...$this->summary($o, $this->clock->nowUtc()),
            'pickup_date' => $o->pickupDate,
            'notes' => $o->notes,
            'discrepancy_note' => $o->discrepancyNote,
            'charged_minor' => $o->chargedMinor,
            'delivered_at' => $o->deliveredAt?->format('Y-m-d\TH:i:s\Z'),
            'billable_minor' => $o->billableMinor(),
            'lines' => array_map(static fn (LaundryLine $l): array => [
                'id' => $l->id, 'item_name' => $l->itemName, 'brand' => $l->brand, 'quantity' => $l->quantity, 'verified_quantity' => $l->verifiedQuantity,
                'unit_price_minor' => $l->unitPriceMinor, 'treatment_name' => $l->treatmentName, 'treatment_extra_minor' => $l->treatmentExtraMinor, 'express_extra_minor' => $l->expressExtraMinor,
                'piece_minor' => $l->pieceMinor(), 'condition_note' => $l->conditionNote, 'total_minor' => $l->totalMinor(),
            ], $o->lines),
            'history' => $history,
        ];
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }
    }

    /** @param list<string> $permissions */
    private function authorizeAny(PropertyId $property, string $actorId, array $permissions): void
    {
        $this->assertProperty($property);

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use laundry.');
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $this->authorizeAny($property, $actorId, [$permission]);
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
