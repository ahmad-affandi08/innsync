<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Domain;

/**
 * Where a guest's laundry is (FR-LDY-003): sent by housekeeping, received and counted by the laundry, washed, dried, ironed,
 * ready (handed back to housekeeping, and charged), then delivered to the room against a receipt. A cancelled order was
 * stopped before any work began. A claimed order was turned into a claim when the guest checked out (FR-LDY-012).
 */
enum LaundryStatus: string
{
    case Sent = 'sent';
    case Received = 'received';
    case Washing = 'washing';
    case Drying = 'drying';
    case Ironing = 'ironing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    /** Turned into a claim at check-out (FR-LDY-012): the order left the laundry's work and goes through the claim procedure. */
    case Claimed = 'claimed';

    /** Still the laundry's or the guest's business: not delivered and not cancelled. */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Delivered, self::Cancelled, self::Claimed], true);
    }

    /** The processing step that follows, once the order has been received. */
    public function nextStep(): ?self
    {
        return match ($this) {
            self::Received => self::Washing,
            self::Washing => self::Drying,
            self::Drying => self::Ironing,
            self::Ironing => self::Ready,
            default => null,
        };
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Sent, self::Received], true);
    }
}
