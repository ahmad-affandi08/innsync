<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reminders;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * Makes the reminders nobody should have to remember to write: a tentative booking that arrives soon and is still not confirmed, and a hold that is about to lapse. Each is made once (by key), for today,
 * and shows on the front desk page like any reminder. It only reminds; it never confirms, releases or cancels anything, because that is the desk's decision.
 */
final readonly class AutoReminderService
{
    public function __construct(private AutoReminderSource $source, private BusinessDateProvider $businessDate, private IdentifierGenerator $ids) {}

    /** @return int how many reminders were made */
    public function run(PropertyId $property, int $daysAhead): int
    {
        $today = $this->businessDate->current($property)->toString();
        $until = (new DateTimeImmutable($today))->modify('+'.max(0, $daysAhead).' days')->format('Y-m-d');
        $made = 0;

        foreach ($this->source->tentativeArrivals($property, $until) as $r) {
            $text = __('desk.auto.tentative', ['number' => $r['number'], 'guest' => $r['guest_name'], 'date' => $r['arrival']]);
            $made += $this->source->addOnce($property, $this->ids->next(), 'tentative:'.$r['id'], $today, $text, $r['id']) ? 1 : 0;
        }

        $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');

        foreach ($this->source->lapsingHolds($property, $tomorrow) as $h) {
            $text = __('desk.auto.hold', ['rooms' => $h['rooms'], 'start' => $h['start'], 'end' => $h['end'], 'date' => $h['expires_on']]);
            $made += $this->source->addOnce($property, $this->ids->next(), 'hold:'.$h['id'], $today, $text, null) ? 1 : 0;
        }

        return $made;
    }
}
