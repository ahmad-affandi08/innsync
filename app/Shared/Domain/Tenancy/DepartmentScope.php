<?php

declare(strict_types=1);

namespace App\Shared\Domain\Tenancy;

use InvalidArgumentException;

/**
 * The identity of a department as a scope of a role assignment (NFR-06). A grant to a resource names it by a ULID, but a department is one of a fixed list of
 * codes, not a record, so its ULID is derived from the property and the code: the same two always give the same id, nothing has to be stored, and the id
 * of a department of one property is never the id of the same department of another. The first character is 0 to 7 as a ULID's is.
 */
final class DepartmentScope
{
    /** The departments of a property, as finance, inventory and human resource name them. */
    public const DEPARTMENTS = ['front_office', 'housekeeping', 'laundry', 'fnb', 'kitchen', 'maintenance', 'hr', 'finance', 'purchasing', 'general'];

    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function idFor(PropertyId $property, string $department): string
    {
        if (! in_array($department, self::DEPARTMENTS, true)) {
            throw new InvalidArgumentException('Unknown department.');
        }

        $bytes = substr(hash('sha256', 'innsync:department:'.$property->toString().':'.$department, true), 0, 16);
        // 128 bits, with two zero bits in front, are 26 groups of five bits.
        $bits = '00';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $id = '';

        foreach (str_split($bits, 5) as $group) {
            $id .= self::ALPHABET[bindec($group)];
        }

        return $id;
    }

    /** The department a scope id stands for in this property, or null when it is not one. */
    public static function departmentOf(PropertyId $property, string $scopeId): ?string
    {
        $scopeId = strtolower($scopeId);

        foreach (self::DEPARTMENTS as $department) {
            if (self::idFor($property, $department) === $scopeId) {
                return $department;
            }
        }

        return null;
    }
}
