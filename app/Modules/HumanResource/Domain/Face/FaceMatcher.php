<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Domain\Face;

/** Compares face descriptors: the 128 numbers a phone's browser makes from a face. Pure arithmetic; nothing here sees a picture. */
final class FaceMatcher
{
    public const SIZE = 128;

    /** True for exactly 128 finite numbers within the range a descriptor takes. */
    public static function valid(mixed $descriptor): bool
    {
        if (! is_array($descriptor) || count($descriptor) !== self::SIZE || array_keys($descriptor) !== range(0, self::SIZE - 1)) {
            return false;
        }

        foreach ($descriptor as $v) {
            if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v) || abs((float) $v) > 3.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<float|int>  $a
     * @param  list<float|int>  $b
     */
    public static function distance(array $a, array $b): float
    {
        $sum = 0.0;

        for ($i = 0; $i < self::SIZE; $i++) {
            $d = (float) $a[$i] - (float) $b[$i];
            $sum += $d * $d;
        }

        return sqrt($sum);
    }

    /**
     * The closest of the registered descriptors to the one just taken.
     *
     * @param  list<list<float|int>>  $registered
     * @param  list<float|int>  $probe
     */
    public static function nearest(array $registered, array $probe): float
    {
        $best = INF;

        foreach ($registered as $r) {
            $best = min($best, self::distance($r, $probe));
        }

        return $best;
    }

    /**
     * True when every pair of the descriptors is within the distance: the photos taken at registration are of one person.
     *
     * @param  list<list<float|int>>  $samples
     */
    public static function consistent(array $samples, float $maxDistance): bool
    {
        for ($i = 0; $i < count($samples); $i++) {
            for ($j = $i + 1; $j < count($samples); $j++) {
                if (self::distance($samples[$i], $samples[$j]) > $maxDistance) {
                    return false;
                }
            }
        }

        return true;
    }
}
