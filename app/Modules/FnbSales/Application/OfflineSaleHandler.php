<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Offline\ConflictAction;
use App\Shared\Application\Offline\OfflineContext;
use App\Shared\Application\Offline\OfflineEnvelope;
use App\Shared\Application\Offline\OfflineOperationHandler;
use App\Shared\Application\Offline\OfflineOutcome;
use App\Shared\Application\Transactions\TransactionRunner;

/**
 * A sale taken on the register while the network was down (FR-FBS-010). The device keeps it in its encrypted queue under an operation ID; when the network is back the
 * foundation applies it once, whatever the number of retries: the bill is opened, every line ordered, the order sent to the stations and, when the guest paid on the spot,
 * the payment recorded and the bill settled, all in one transaction, so a failure leaves nothing behind and a retry runs again.
 *
 * The server prices the sale as it stands when it arrives and checks it against what the device showed the guest. A line whose price is not the one shown, a dish that
 * ran out, a table that has a bill meanwhile, a cashier with no open shift or less cash than the bill comes to is not guessed at: the sale is refused as a conflict for a
 * person to settle, and nothing is booked. Only cash and card are taken offline; QRIS needs the provider and a room charge needs the folio. The sale is booked on the
 * business date it syncs on.
 */
final readonly class OfflineSaleHandler implements OfflineOperationHandler
{
    public const TYPE = 'fnb.pos.sale';

    public const MAX_LINES = 60;

    public function __construct(private BillService $bills, private PaymentService $payments, private TransactionRunner $transactions) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function payloadVersions(): array
    {
        return [1];
    }

    public function permission(): ?string
    {
        return FnbAccess::POS_OPERATE;
    }

    public function handle(OfflineEnvelope $envelope, OfflineContext $context): OfflineOutcome
    {
        $sale = $this->shape($envelope->payload);

        if ($sale === null) {
            return OfflineOutcome::rejected('invalid_payload');
        }

        $property = $context->propertyId;
        $actor = $context->actorId;

        try {
            return $this->transactions->run(function () use ($property, $actor, $sale): OfflineOutcome {
                $view = $this->bills->open($property, $actor, $sale['outlet_id'], $sale['table_id'], null, $sale['covers'], $sale['note']);
                $billId = (string) $view['bill']['id'];
                $lock = (int) $view['bill']['lock_version'];
                $channelPriced = [];

                foreach ($sale['lines'] as $line) {
                    $view = $this->bills->addLine($property, $actor, $billId, $lock, $line['item_id'], $line['variant_id'], $line['modifier_ids'], $line['quantity'], $line['note']);
                    $lock = (int) $view['bill']['lock_version'];
                    $added = $view['bill']['lines'][count($view['bill']['lines']) - 1];
                    $channelPriced[] = (int) $added['unit_price_minor'] * $line['quantity'];

                    if ((int) $added['unit_price_minor'] !== $line['unit_price_minor']) {
                        throw new SaleRefused(OfflineOutcome::conflict('price_changed', ConflictAction::Review));
                    }
                }

                $view = $this->bills->send($property, $actor, $billId, $lock);
                $lock = (int) $view['bill']['lock_version'];

                if ($sale['payment'] !== null) {
                    $total = (int) $view['totals']['total_minor'];

                    if ((bool) $view['totals']['scheme_missing']) {
                        throw new SaleRefused(OfflineOutcome::conflict('charge_scheme_missing', ConflictAction::Review));
                    }

                    $tendered = $sale['payment']['method'] === 'cash' ? $sale['payment']['tendered_minor'] : null;

                    if ($sale['payment']['method'] === 'cash' && ($tendered === null || $tendered < $total)) {
                        throw new SaleRefused(OfflineOutcome::conflict('cash_short', ConflictAction::Review));
                    }

                    $view = $this->payments->pay($property, $actor, $billId, $lock, $sale['payment']['method'], $total, $tendered, $sale['payment']['reference'], null, null);
                }

                return OfflineOutcome::accepted(['bill_number' => (string) $view['bill']['number'], 'total_minor' => (int) $view['totals']['total_minor'], 'status' => (string) $view['bill']['status']]);
            });
        } catch (SaleRefused $refused) {
            return $refused->outcome;
        } catch (Refusal $refusal) {
            return $this->outcomeOf($refusal);
        }
    }

    /** A refusal of the business rules, as the outcome the device and the person who settles it understand. */
    private function outcomeOf(Refusal $refusal): OfflineOutcome
    {
        $message = $refusal->getMessage();

        return match (true) {
            $refusal->status() === 403 => OfflineOutcome::rejected('forbidden'),
            $refusal->status() === 404 => OfflineOutcome::rejected('not_found'),
            $refusal->status() === 422 => OfflineOutcome::rejected('invalid_sale'),
            str_contains($message, 'sold out') => OfflineOutcome::conflict('sold_out', ConflictAction::Review),
            str_contains($message, 'open bill already') => OfflineOutcome::conflict('table_busy', ConflictAction::Review),
            str_contains($message, 'cashier shift') => OfflineOutcome::conflict('shift_required', ConflictAction::Review),
            default => OfflineOutcome::conflict('sale_conflict', ConflictAction::Review),
        };
    }

    /**
     * @param  array<array-key, mixed>  $p
     * @return array{outlet_id: string, table_id: string|null, covers: int, note: string|null, lines: list<array{item_id: string, variant_id: string|null, modifier_ids: list<string>, quantity: int, note: string|null, unit_price_minor: int}>, payment: array{method: string, tendered_minor: int|null, reference: string|null}|null}|null
     */
    private function shape(array $p): ?array
    {
        $outlet = $p['outlet_id'] ?? null;
        $table = $p['table_id'] ?? null;
        $covers = $p['covers'] ?? 1;
        $note = $p['note'] ?? null;
        $lines = $p['lines'] ?? null;
        $payment = $p['payment'] ?? null;

        if (! is_string($outlet) || ($table !== null && ! is_string($table)) || ! is_int($covers) || ($note !== null && ! is_string($note)) || ! is_array($lines) || $lines === [] || count($lines) > self::MAX_LINES || ($payment !== null && ! is_array($payment))) {
            return null;
        }

        $shaped = [];

        foreach ($lines as $l) {
            if (! is_array($l) || ! is_string($l['item_id'] ?? null) || ! is_int($l['quantity'] ?? null) || ! is_int($l['unit_price_minor'] ?? null) || ($l['variant_id'] ?? null) !== null && ! is_string($l['variant_id'])
                || ! is_array($l['modifier_ids'] ?? []) || ($l['note'] ?? null) !== null && ! is_string($l['note'])) {
                return null;
            }

            foreach ($l['modifier_ids'] ?? [] as $m) {
                if (! is_string($m)) {
                    return null;
                }
            }

            $shaped[] = ['item_id' => $l['item_id'], 'variant_id' => $l['variant_id'] ?? null, 'modifier_ids' => array_values($l['modifier_ids'] ?? []), 'quantity' => $l['quantity'], 'note' => $l['note'] ?? null, 'unit_price_minor' => $l['unit_price_minor']];
        }

        $pay = null;

        if ($payment !== null) {
            $method = $payment['method'] ?? null;
            $tendered = $payment['tendered_minor'] ?? null;
            $reference = $payment['reference'] ?? null;

            if (! in_array($method, ['cash', 'card'], true) || ($tendered !== null && ! is_int($tendered)) || ($reference !== null && ! is_string($reference))) {
                return null;
            }

            $pay = ['method' => $method, 'tendered_minor' => $tendered, 'reference' => $reference];
        }

        return ['outlet_id' => $outlet, 'table_id' => $table, 'covers' => $covers, 'note' => $note, 'lines' => $shaped, 'payment' => $pay];
    }
}
