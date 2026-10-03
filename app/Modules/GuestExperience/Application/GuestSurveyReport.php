<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;

/** The answers of the satisfaction survey for the people who run the hotel (FR-GST-016): the latest answers and the average of each rating over the last 90 days. */
final readonly class GuestSurveyReport
{
    public const DAYS = 90;

    public function __construct(private GuestHelpStore $store, private GuestAccess $access, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, GuestAccess::ORDER_MANAGE, 'This person may not see the guest surveys.');
        $rows = $this->store->surveysSince($property, $this->clock->nowUtc()->modify('-'.self::DAYS.' days'), 200);
        $sums = ['overall' => [0, 0], 'room_rating' => [0, 0], 'service_rating' => [0, 0], 'food_rating' => [0, 0], 'value_rating' => [0, 0]];

        foreach ($rows as $r) {
            foreach (array_keys($sums) as $field) {
                if ($r[$field] !== null) {
                    $sums[$field][0] += (int) $r[$field];
                    $sums[$field][1]++;
                }
            }
        }

        return [
            'days' => self::DAYS, 'count' => count($rows),
            'averages' => array_map(static fn (array $s): ?int => $s[1] === 0 ? null : intdiv($s[0] * 100 + intdiv($s[1], 2), $s[1]), $sums),
            'surveys' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'overall' => (int) $r['overall'], 'room' => $r['room_rating'] === null ? null : (int) $r['room_rating'], 'service' => $r['service_rating'] === null ? null : (int) $r['service_rating'],
                'food' => $r['food_rating'] === null ? null : (int) $r['food_rating'], 'value' => $r['value_rating'] === null ? null : (int) $r['value_rating'], 'comment' => $r['comment'], 'complaint_opened' => $r['complaint_id'] !== null,
                'at' => str_replace(' ', 'T', substr((string) $r['created_at'], 0, 19)).'Z',
            ], $rows),
        ];
    }
}
