<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GiftCards;

use App\Modules\FrontOffice\Application\Cashier\ShiftAttribution;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\GiftCards\GiftCardRedemption;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Privacy\FieldCipher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;

/**
 * Gift cards and vouchers (docs/OPERATIONS/GIFT-CARDS.md). A card is sold for an amount, paid for in advance, and spent as a payment on a guest folio or a restaurant bill; the balance is on the card and every
 * movement is an entry that is never changed. The code is shown once when the card is made and kept only as a keyed hash, so a lost code cannot be looked up: the card is voided and a new one made. A card is good
 * for twelve months unless the sale says otherwise (baseline, `config/frontdesk.php`). A card that is voided says whether the money went back to the buyer or was written off.
 */
final readonly class GiftCardService implements GiftCardRedemption
{
    public const ISSUE_PERMISSION = 'front-office.gift-card.issue';

    public const VOID_PERMISSION = 'front-office.gift-card.void';

    public const VIEW_PERMISSION = 'front-office.gift-card.view';

    /** How the buyer paid; "complimentary" is a voucher the hotel gives away (it brings in no money). */
    public const METHODS = ['cash', 'card', 'qris', 'bank_transfer', 'complimentary'];

    public const DISPOSITIONS = ['refunded', 'written_off'];

    public const MAX_MINOR = 500_000_000_00;

    public function __construct(
        private GiftCardStore $store,
        private ShiftAttribution $shifts,
        private PermissionChecker $permissions,
        private PropertyContext $context,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private FieldCipher $cipher,
        private IdempotentExecutor $executor,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->scope($property);

        if (! $this->may($property, $actorId, self::VIEW_PERMISSION) && ! $this->may($property, $actorId, self::ISSUE_PERMISSION)) {
            throw Refusal::forbidden('This person may not see gift cards.');
        }

        $today = $this->businessDate->current($property)->toString();

        return [
            'currency' => $this->currency->currencyOf($property), 'today' => $today, 'default_expiry' => $this->defaultExpiry($today), 'methods' => self::METHODS, 'dispositions' => self::DISPOSITIONS,
            'cards' => array_map(fn (array $c): array => $this->shape($c, $today), $this->store->cards($property, 200)),
            'may' => ['issue' => $this->may($property, $actorId, self::ISSUE_PERMISSION), 'void' => $this->may($property, $actorId, self::VOID_PERMISSION)],
        ];
    }

    /** What a code is worth now: for the desk, to answer a guest who asks. @return array<string, mixed> */
    public function lookup(PropertyId $property, string $actorId, string $code): array
    {
        $this->scope($property);

        if (! $this->may($property, $actorId, self::VIEW_PERMISSION) && ! $this->may($property, $actorId, self::ISSUE_PERMISSION)) {
            throw Refusal::forbidden('This person may not see gift cards.');
        }

        $card = $this->store->byHash($property, $this->hash($code)) ?? throw Refusal::notFound('No gift card has this code.');

        return $this->shape($card, $this->businessDate->current($property)->toString());
    }

    /** @return array{card: array<string, mixed>, code: string|null, replayed: bool} the code is shown here and never again: a sale sent twice answers the second time with the card and no code */
    public function issue(PropertyId $property, string $actorId, IdempotencyKey $key, int $amountMinor, string $kind, string $method, ?string $reference, ?string $expiresOn, ?string $holderNote): array
    {
        $this->scope($property);

        if (! $this->may($property, $actorId, self::ISSUE_PERMISSION)) {
            throw Refusal::forbidden('This person may not sell gift cards.');
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        if (! in_array($kind, ['gift_card', 'voucher'], true)) {
            throw Refusal::invalid('Choose a gift card or a voucher.', ['kind']);
        }

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose how it was paid.', ['payment_method']);
        }

        $reference = $reference === null || trim($reference) === '' ? null : mb_substr(trim($reference), 0, 60);

        if ($method !== 'cash' && $method !== 'complimentary' && ($reference === null || mb_strlen($reference) < 4)) {
            throw Refusal::invalid('Give the approval code, transfer reference or QRIS reference of the payment.', ['reference']);
        }

        $holderNote = $holderNote === null || trim($holderNote) === '' ? null : trim($holderNote);

        if ($holderNote !== null && mb_strlen($holderNote) > 120) {
            throw Refusal::invalid('The note is at most 120 characters.', ['holder_note']);
        }

        $today = $this->businessDate->current($property)->toString();
        $expiry = $expiresOn === null || $expiresOn === '' ? $this->defaultExpiry($today) : $expiresOn;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) !== 1 || $expiry < $today || $expiry > DateMath::addDays($today, 365 * 5)) {
            throw Refusal::invalid('The last day is today or later, and within five years.', ['expires_on']);
        }

        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();
        $currency = $this->currency->currencyOf($property);

        $code = null;
        $result = $this->executor->execute(
            new IdempotencyRequest($property, $key, 'gift_card.issue', ['amount_minor' => $amountMinor, 'kind' => $kind, 'method' => $method, 'reference' => $reference, 'expires_on' => $expiry, 'holder_note' => $holderNote], $actor),
            function () use ($property, $actor, $amountMinor, $kind, $method, $reference, $expiry, $holderNote, $today, $now, $currency, &$code): array {
                $shift = null;

                if ($method === 'cash') {
                    $this->shifts->assertMayHandleMoney($property, $actor);
                    $shift = $this->store->openShiftOf($property, $actor);
                }

                for ($try = 0; $try < 5; $try++) {
                    $made = $this->newCode();
                    $id = $this->ids->next();

                    if (! $this->store->addCard($property, [
                        'id' => $id, 'code_hash' => $this->hash($made), 'code_last4' => substr($made, -4), 'kind' => $kind, 'currency' => $currency, 'face_minor' => $amountMinor, 'balance_minor' => $amountMinor, 'expires_on' => $expiry, 'status' => 'active',
                        'holder_note' => $holderNote, 'issue_method' => $method, 'issue_reference' => $reference, 'issue_shift_id' => $shift, 'issued_on' => $today, 'issued_by' => $actor,
                    ], $now)) {
                        continue;
                    }

                    $this->store->addEntry($property, ['id' => $this->ids->next(), 'card_id' => $id, 'kind' => 'issue', 'delta_minor' => $amountMinor, 'balance_after_minor' => $amountMinor, 'source_type' => 'issue', 'source_ref' => $id, 'disposition' => null, 'note' => null, 'business_date' => $today, 'actor_id' => $actor], $now);
                    $this->audit->record(new AuditEntry($property->toString(), $actor, 'gift_card.issued', 'gift_card', $id, null, ['kind' => $kind, 'last4' => substr($made, -4), 'amount_minor' => $amountMinor, 'method' => $method, 'expires_on' => $expiry]));
                    $code = $made;

                    // Only the id is kept for a replay: the code itself is never stored.
                    return ['card_id' => $id];
                }

                throw Refusal::stateConflict('A code could not be made. Try again.');
            },
        );

        return ['card' => $this->shape($this->store->card($property, (string) $result->payload['card_id']) ?? throw Refusal::notFound('Gift card not found.'), $today), 'code' => $code, 'replayed' => $result->replayed];
    }

    /** Voids a card: what is left on it goes back to the buyer or is written off, with the reason. @return array<string, mixed> */
    public function void(PropertyId $property, string $actorId, string $cardId, string $disposition, string $reason): array
    {
        $this->scope($property);

        if (! $this->may($property, $actorId, self::VOID_PERMISSION)) {
            throw Refusal::forbidden('This person may not void gift cards.');
        }

        $reason = trim($reason);

        if (! in_array($disposition, self::DISPOSITIONS, true)) {
            throw Refusal::invalid('Say whether the rest was paid back or written off.', ['disposition']);
        }

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();

        $this->transactions->run(function () use ($property, $actor, $cardId, $disposition, $reason, $today): void {
            $card = $this->store->card($property, strtolower($cardId)) ?? throw Refusal::notFound('Gift card not found.');
            $now = $this->clock->nowUtc();
            $left = $this->store->voidCard($property, $card['id'], $now) ?? throw Refusal::stateConflict('This card is void already.');

            if ($left > 0) {
                $this->store->addEntry($property, ['id' => $this->ids->next(), 'card_id' => $card['id'], 'kind' => 'void', 'delta_minor' => -$left, 'balance_after_minor' => 0, 'source_type' => 'void', 'source_ref' => $card['id'], 'disposition' => $disposition, 'note' => $reason, 'business_date' => $today, 'actor_id' => $actor], $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'gift_card.voided', 'gift_card', $card['id'], ['status' => 'active', 'balance_minor' => $left], ['status' => 'void', 'disposition' => $left > 0 ? $disposition : null], $reason));
        });

        return $this->shape($this->store->card($property, strtolower($cardId)) ?? throw Refusal::notFound('Gift card not found.'), $today);
    }

    public function redeem(PropertyId $property, string $actorId, string $code, int $amountMinor, string $currency, string $sourceType, string $sourceRef): string
    {
        $card = $this->store->byHash($property, $this->hash($code));
        $refusal = Refusal::invalid('This gift card cannot pay this.', ['reference']);

        if ($card === null) {
            throw $refusal;
        }

        $masked = 'GC-...'.$card['code_last4'];
        $done = $this->store->entryBySource($property, 'redeem', $sourceType, strtolower($sourceRef));

        if ($done !== null) {
            return $masked;
        }

        $today = $this->businessDate->current($property)->toString();

        if ($card['status'] !== 'active' || ($card['expires_on'] !== null && substr((string) $card['expires_on'], 0, 10) < $today) || $card['currency'] !== $currency || $amountMinor < 1 || (int) $card['balance_minor'] < $amountMinor) {
            throw $refusal;
        }

        $now = $this->clock->nowUtc();

        if (! $this->store->debit($property, $card['id'], $amountMinor, $now)) {
            throw $refusal;
        }

        $left = (int) $card['balance_minor'] - $amountMinor;
        $this->store->addEntry($property, ['id' => $this->ids->next(), 'card_id' => $card['id'], 'kind' => 'redeem', 'delta_minor' => -$amountMinor, 'balance_after_minor' => $left, 'source_type' => $sourceType, 'source_ref' => strtolower($sourceRef), 'disposition' => null, 'note' => null, 'business_date' => $today, 'actor_id' => strtolower($actorId)], $now);
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'gift_card.redeemed', 'gift_card', $card['id'], ['balance_minor' => (int) $card['balance_minor']], ['balance_minor' => $left, 'amount_minor' => $amountMinor, 'source' => $sourceType]));

        return $masked;
    }

    public function restore(PropertyId $property, string $actorId, string $sourceType, string $sourceRef): void
    {
        $sourceRef = strtolower($sourceRef);
        $taken = $this->store->entryBySource($property, 'redeem', $sourceType, $sourceRef);

        if ($taken === null || $this->store->entryBySource($property, 'restore', $sourceType, $sourceRef) !== null) {
            return;
        }

        $amount = -(int) $taken['delta_minor'];
        $card = $this->store->card($property, $taken['card_id']) ?? throw Refusal::notFound('Gift card not found.');
        $now = $this->clock->nowUtc();
        $this->store->credit($property, $card['id'], $amount, $now);
        $this->store->addEntry($property, ['id' => $this->ids->next(), 'card_id' => $card['id'], 'kind' => 'restore', 'delta_minor' => $amount, 'balance_after_minor' => (int) $card['balance_minor'] + $amount, 'source_type' => $sourceType, 'source_ref' => $sourceRef, 'disposition' => null, 'note' => null, 'business_date' => $this->businessDate->current($property)->toString(), 'actor_id' => strtolower($actorId)], $now);
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'gift_card.restored', 'gift_card', $card['id'], ['balance_minor' => (int) $card['balance_minor']], ['balance_minor' => (int) $card['balance_minor'] + $amount, 'amount_minor' => $amount, 'source' => $sourceType]));
    }

    /** The code as people type it: letters and digits, upper case, the look-alikes read the way the code is written. */
    public static function normalize(string $code): string
    {
        return strtr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)), ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    private function hash(string $code): string
    {
        $normal = self::normalize($code);
        $normal = str_starts_with($normal, 'GC') && strlen($normal) === 14 ? substr($normal, 2) : $normal;

        return $this->cipher->blindIndex('gift_card', $normal);
    }

    /** `GC-XXXX-XXXX-XXXX` from an alphabet without the look-alikes. */
    private function newCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $chars = '';

        for ($i = 0; $i < 12; $i++) {
            $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'GC-'.implode('-', str_split($chars, 4));
    }

    private function defaultExpiry(string $today): string
    {
        return DateMath::format('Y-m-d', '+'.max(1, (int) config('frontdesk.gift_card_valid_months', 12)).' months', $today);
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function shape(array $c, string $today): array
    {
        $expires = $c['expires_on'] === null ? null : substr((string) $c['expires_on'], 0, 10);
        $state = $c['status'] === 'void' ? 'void' : ($expires !== null && $expires < $today ? 'expired' : ((int) $c['balance_minor'] === 0 ? 'spent' : 'active'));

        return [
            'id' => $c['id'], 'kind' => $c['kind'], 'last4' => $c['code_last4'], 'currency' => $c['currency'], 'face_minor' => (int) $c['face_minor'], 'balance_minor' => (int) $c['balance_minor'], 'expires_on' => $expires, 'state' => $state,
            'holder_note' => $c['holder_note'], 'issue_method' => $c['issue_method'], 'issued_on' => substr((string) $c['issued_on'], 0, 10),
        ];
    }

    private function may(PropertyId $property, string $actorId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($actorId, $permission, $property);
    }

    private function scope(PropertyId $property): void
    {
        $current = $this->context->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
