<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Privacy\ConsentLedger;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Throwable;

/**
 * The two messages the hotel may switch on for guests who booked on its own web page: a reminder the day before arrival and a thank-you after the stay. Both are off until the hotel switches them on.
 * A message goes only to a booking whose guest agreed to the privacy notice when booking (a consent record exists and was not withdrawn), only by email, and once per booking and kind.
 * Bookings made by staff carry no such record and are never written to. The wording is fixed in the language files and carries no price, no payment detail and no link.
 */
final readonly class GuestMessageService
{
    public const KINDS = ['pre_arrival', 'thank_you'];

    public function __construct(private OnlineBookingStore $store, private ConsentLedger $consents, private EmailNotifier $mail, private PropertyProfileReader $profile, private BusinessDateProvider $businessDate) {}

    /** @return array{pre_arrival: int, thank_you: int} how many were sent */
    public function run(PropertyId $property, string $locale): array
    {
        $settings = $this->store->settings($property);
        $sent = ['pre_arrival' => 0, 'thank_you' => 0];

        if ($settings === null) {
            return $sent;
        }

        $today = $this->businessDate->current($property)->toString();
        $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $hotel = $this->profile->nameOf($property) ?? '';
        $plan = [['pre_arrival', $settings['remind_before_arrival'], $tomorrow], ['thank_you', $settings['thank_after_stay'], $today]];

        foreach ($plan as [$kind, $on, $day]) {
            if (! $on) {
                continue;
            }

            foreach ($this->store->messageCandidates($property, $kind, $day) as $r) {
                if (! $this->consents->isGranted($property, 'reservation', $r['id'], OnlineBookingService::CONSENT_PURPOSE)) {
                    continue;
                }

                $vars = ['hotel' => $hotel, 'name' => $r['guest_name'], 'number' => $r['number'], 'arrival' => $r['arrival'], 'departure' => $r['departure']];

                try {
                    $ok = $this->mail->notify($r['guest_email'], __('booking.'.$kind.'_subject', $vars, $locale), __('booking.'.$kind.'_body', $vars, $locale));
                } catch (Throwable $e) {
                    report($e);
                    $ok = false;
                }

                if ($ok && $this->store->logSent($property, $r['id'], $kind)) {
                    $sent[$kind]++;
                }
            }
        }

        return $sent;
    }
}
