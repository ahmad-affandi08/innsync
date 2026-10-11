<?php

declare(strict_types=1);

namespace App\Shared\Application\GiftCards;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Spending a gift card or voucher as a payment (the front office folio, a restaurant bill) and giving the money back to the card when that payment is reversed or refunded. The caller has authorised the
 * payment itself and runs this in the same transaction as the payment it makes; the card is the property's own. Nothing here tells a caller why a card was refused beyond "this card cannot pay this".
 */
interface GiftCardRedemption
{
    /**
     * Takes `$amountMinor` from the card with this code, once per source (`$sourceType` + `$sourceRef`: a second call for the same source takes nothing and answers the same).
     *
     * @return string a reference that recognises the card without giving its code away (`GC-...1234`)
     *
     * @throws Refusal when the code is unknown, the card is void or expired, in another currency, or holds less than the amount
     */
    public function redeem(PropertyId $property, string $actorId, string $code, int $amountMinor, string $currency, string $sourceType, string $sourceRef): string;

    /** Gives back what that source took, once. Does nothing when the source never took anything from a card. */
    public function restore(PropertyId $property, string $actorId, string $sourceType, string $sourceRef): void;
}
