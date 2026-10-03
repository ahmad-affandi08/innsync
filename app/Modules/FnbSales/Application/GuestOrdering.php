<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the guest self-service needs of the point of sale (FR-GST-010..014, -019): the table behind a code, the menu with what is sold out, an order that reaches the bill and the kitchen like one a waiter
 * took (marked as coming from the guest), and how far the lines are. The caller has proved who is asking (a code scanned, and for a room the stay); F&B sales records the work under the guest self-service
 * account of the property, which holds only the right to take orders, so nothing here discounts, voids or takes a payment.
 */
interface GuestOrdering
{
    /** @return array{table_id: string, code: string, outlet_id: string, outlet: string}|null the table with the outlet it belongs to, when both are in use */
    public function table(PropertyId $property, string $tableId): ?array;

    /** @return list<array{id: string, name: string}> the outlets of the kind room service that are in use */
    public function roomOutlets(PropertyId $property): array;

    /** @return list<array{id: string, code: string, name: string, kind: string}> the tables in use with the outlet each belongs to */
    public function tables(PropertyId $property): array;

    /**
     * The menu of an outlet as a guest sees it: the categories and the dishes in use, each with the price that holds now for the way it is sold and whether it is sold out.
     *
     * @return list<array<string, mixed>>
     */
    public function menu(PropertyId $property, string $outletId, string $channel): array;

    /**
     * Places an order: opens the bill of the table (or adds to the one it has open), or opens a room service order for the room, orders every line and sends it to the stations, in one transaction.
     *
     * @param  array{kind: string, outlet_id: string, table_id: string|null, room_id: string|null}  $target
     * @param  list<array{item_id: string, variant_id: string|null, modifier_ids: list<string>, quantity: int, note: string|null}>  $lines
     * @return array{bill_id: string, bill_number: string, line_ids: list<string>, subtotal_minor: int}
     *
     * @throws Refusal when a dish is sold out, not on this menu or ordered with the wrong choices
     */
    public function place(PropertyId $property, array $target, array $lines, ?string $note): array;

    /**
     * How far lines are: what was ordered, whether it was sent, and how far the kitchen or the bar is. Only the lines asked for are answered.
     *
     * @param  list<string>  $lineIds
     * @return list<array{line_id: string, name: string, variant: string|null, quantity: int, status: string, prep_status: string, total_minor: int}>
     */
    public function progress(PropertyId $property, string $billId, array $lineIds): array;

    /** @return array{status: string, room_service: string|null}|null the state of a bill and, for a room order, how far its delivery is */
    public function stateOf(PropertyId $property, string $billId): ?array;

    /**
     * What the bill of an order comes to now, with the service charge and tax, for the guest to see before paying.
     *
     * @return array{total_minor: int, subtotal_minor: int, service_charge_minor: int, tax_minor: int, currency: string}|null
     */
    public function totalsOf(PropertyId $property, string $billId): ?array;
}
