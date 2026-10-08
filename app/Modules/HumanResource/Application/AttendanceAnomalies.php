<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

/**
 * The marks of a clock-in from a phone that deserves a second look. Nothing here refuses a clock-in or accuses anyone: a mark puts the clock-in in front of a supervisor,
 * who decides (`AttendanceReviewService`). The marks are worked out from the evidence kept with each clock-in, so they follow it whenever it is read.
 *
 *  - `shared_device`  the same phone clocked in for two or more people (a buddy punch is the usual reason).
 *  - `reused_photo`   the same selfie, byte for byte, twice: a camera never takes the same picture twice, so one came from a gallery.
 *  - `same_spot`      five or more clock-ins by one person at exactly the same distance from the property: a real position drifts, a faked one does not.
 *  - `exact_position` the phone claimed an accuracy of 1 m or better, which a real phone does not.
 *  - `poor_position`  the phone's accuracy was worse than the allowed distance, so it proves little about where the person was.
 *  - `face_mismatch` the face in the selfie was not the one registered for the person (see `FaceService`).
 *  - `face_missing`  face matching was on, but the person is not registered or the selfie showed no face, so nothing was checked.
 *  - `new_device`     the first clock-in from a phone after three or more from other phones (a new phone is normal, a changed phone is worth a glance).
 */
final class AttendanceAnomalies
{
    public const SHARED_DEVICE = 'shared_device';

    public const REUSED_PHOTO = 'reused_photo';

    public const SAME_SPOT = 'same_spot';

    public const EXACT_POSITION = 'exact_position';

    public const POOR_POSITION = 'poor_position';

    public const NEW_DEVICE = 'new_device';

    public const FACE_MISMATCH = 'face_mismatch';

    public const FACE_MISSING = 'face_missing';

    /** The marks that are strong evidence on their own; the review list shows them first. */
    public const STRONG = [self::SHARED_DEVICE, self::REUSED_PHOTO, self::FACE_MISMATCH];

    public const SAME_SPOT_FROM = 5;

    public const NEW_DEVICE_AFTER = 3;

    /**
     * @param  list<array<string, mixed>>  $records  attendance rows (as the store returns them) of a period long enough to compare people and days
     * @return array<string, list<string>> `"<attendance id>:in"` or `":out"` => its marks; clock-ins without a mark are left out
     */
    public static function flag(array $records, int $radiusMetres): array
    {
        $events = [];

        foreach ($records as $r) {
            foreach (['in', 'out'] as $side) {
                $at = $r[$side.'_at'] ?? null;
                $method = $side === 'in' ? ($r['in_method'] ?? null) : ($r['out_method'] ?? null);

                if ($at === null || $method !== 'mobile') {
                    continue;
                }

                $events[] = [
                    'key' => $r['id'].':'.$side, 'employee' => (string) $r['employee_id'], 'at' => (string) $at,
                    'distance' => $r[$side.'_distance_m'] ?? null, 'accuracy' => $r[$side.'_accuracy_m'] ?? null,
                    'device' => $r[$side.'_device'] ?? null, 'photo' => $r[$side.'_photo_hash'] ?? null, 'face' => $r[$side.'_face'] ?? null,
                ];
            }
        }

        usort($events, static fn (array $a, array $b): int => [$a['at'], $a['key']] <=> [$b['at'], $b['key']]);
        $marks = [];
        $mark = static function (string $key, string $flag) use (&$marks): void {
            $marks[$key][$flag] = true;
        };

        foreach (self::groups($events, 'device') as $group) {
            if (count(array_unique(array_column($group, 'employee'))) >= 2) {
                foreach ($group as $e) {
                    $mark($e['key'], self::SHARED_DEVICE);
                }
            }
        }

        foreach (self::groups($events, 'photo') as $group) {
            if (count($group) >= 2) {
                foreach ($group as $e) {
                    $mark($e['key'], self::REUSED_PHOTO);
                }
            }
        }

        $bySpot = [];
        foreach ($events as $e) {
            if ($e['distance'] !== null) {
                $bySpot[$e['employee'].'|'.$e['distance']][] = $e;
            }
        }
        foreach ($bySpot as $group) {
            if (count($group) >= self::SAME_SPOT_FROM) {
                foreach ($group as $e) {
                    $mark($e['key'], self::SAME_SPOT);
                }
            }
        }

        foreach ($events as $e) {
            if ($e['face'] === 'mismatch') {
                $mark($e['key'], self::FACE_MISMATCH);
            } elseif ($e['face'] === 'none') {
                $mark($e['key'], self::FACE_MISSING);
            }

            if ($e['accuracy'] !== null && (int) $e['accuracy'] <= 1) {
                $mark($e['key'], self::EXACT_POSITION);
            }

            if ($e['accuracy'] !== null && (int) $e['accuracy'] > $radiusMetres) {
                $mark($e['key'], self::POOR_POSITION);
            }
        }

        $seen = [];
        foreach ($events as $e) {
            if ($e['device'] === null) {
                continue;
            }

            $known = $seen[$e['employee']] ??= ['devices' => [], 'events' => 0];

            if ($known['events'] >= self::NEW_DEVICE_AFTER && ! isset($known['devices'][$e['device']])) {
                $mark($e['key'], self::NEW_DEVICE);
            }

            $seen[$e['employee']]['devices'][$e['device']] = true;
            $seen[$e['employee']]['events'] = $known['events'] + 1;
        }

        return array_map(static fn (array $flags): array => array_keys($flags), $marks);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<list<array<string, mixed>>> events sharing a non-empty value of the field
     */
    private static function groups(array $events, string $field): array
    {
        $groups = [];

        foreach ($events as $e) {
            if ($e[$field] !== null && $e[$field] !== '') {
                $groups[(string) $e[$field]][] = $e;
            }
        }

        return array_values($groups);
    }
}
