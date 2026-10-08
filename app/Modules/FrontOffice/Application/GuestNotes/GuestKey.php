<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestNotes;

/** What tells one guest from another across bookings: the name and the phone digits, kept only as a hash. The same rule groups the guest list. */
final class GuestKey
{
    public static function of(string $name, ?string $phone): string
    {
        return hash('sha256', mb_strtolower(trim($name)).'|'.preg_replace('/\D/', '', (string) $phone));
    }
}
