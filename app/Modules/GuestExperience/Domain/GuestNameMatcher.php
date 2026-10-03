<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Domain;

/**
 * Whether what a guest typed is a name of the guest in the room (FR-GST-011). The guest gives one name, usually the surname; it matches when it is, whatever the case, accents and spaces, a whole name of the
 * guest as the front office has it ("Budi Santoso" matches "santoso" and "Budi" but not "Sant" or "Bu"), and has at least two letters. The room number is the other half of the proof, so a short name alone is not enough
 * to enter another guest's room.
 */
final class GuestNameMatcher
{
    private const FOLD = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c'];

    public static function matches(string $guestName, string $typed): bool
    {
        $typed = self::normalize($typed);

        if (mb_strlen($typed) < 2) {
            return false;
        }

        foreach (preg_split('/\s+/u', trim(mb_strtolower($guestName))) ?: [] as $word) {
            if (self::normalize($word) === $typed) {
                return true;
            }
        }

        return false;
    }

    /** A room number as the front office keeps it: no spaces, upper case. */
    public static function room(string $number): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', trim($number)));
    }

    private static function normalize(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', strtr(mb_strtolower($text), self::FOLD));
    }
}
