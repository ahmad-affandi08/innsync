<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;

final readonly class GuestRoomChargeVerdict implements GuestRoomCharges
{
    public function __construct(private GuestOrderStore $orders) {}

    public function verdict(PropertyId $property, string $billId): ?string
    {
        $states = [];

        foreach ($this->orders->ofBill($property, strtolower($billId)) as $o) {
            if ($o['payment_preference'] === 'room') {
                $states[] = (string) $o['room_charge_state'];
            }
        }

        return match (true) {
            $states === [] => null,
            in_array('rejected', $states, true) => 'rejected',
            in_array('pending', $states, true) => 'pending',
            default => 'verified',
        };
    }
}
