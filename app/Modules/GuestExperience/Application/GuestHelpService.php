<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FrontOffice\Application\GuestDesk\GuestStayDesk;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * What a guest who has proved the stay can do besides ordering (FR-GST-015, FR-GST-016, FR-GST-019): ask for a service, tell the hotel about a problem, follow both, see the bill so far and, near the departure,
 * answer a short survey. A request goes to the department it belongs to in front office exactly as one a receptionist takes; a complaint goes to the complaints list as medium for the staff to re-rate. The guest sees
 * the number and how far each of their own is (received, being done, done), never another guest's. A low rating in the survey also opens a complaint, so someone can put it right before the guest leaves.
 */
final readonly class GuestHelpService
{
    public const SURVEY_FIELDS = ['room_rating', 'service_rating', 'food_rating', 'value_rating'];

    public function __construct(
        private GuestStayDesk $desk,
        private GuestHelpStore $store,
        private PropertyProfileReader $profile,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function help(array $session): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];
        $base = ['hotel' => $this->profile->nameOf($property) ?? '', 'label' => $session['label'], 'verified' => $this->proved($session), 'locked' => $session['locked'], 'categories' => GuestStayDesk::CATEGORIES, 'requests' => []];

        if (! $this->proved($session)) {
            return $base;
        }

        $rows = $this->store->requestsOfStay($property, (string) $session['stay_id'], 20);
        $base['requests'] = array_values(array_filter(array_map(function (array $r) use ($property, $session): ?array {
            $state = $this->desk->statusOf($property, (string) $session['stay_id'], (string) $r['kind'], (string) $r['ref_id']);

            return $state === null ? null : ['id' => $r['id'], 'kind' => $r['kind'], 'category' => $r['category'], 'title' => $r['title'], 'number' => $state['number'], 'status' => $state['status'], 'resolution' => $state['resolution'], 'sent_at' => str_replace(' ', 'T', substr((string) $r['created_at'], 0, 19)).'Z'];
        }, $rows)));

        return $base;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed> the help page after the request
     */
    public function request(array $session, string $clientKey, string $category, string $title, ?string $detail): array
    {
        return $this->send($session, 'request', $clientKey, $category, $title, $detail);
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function complaint(array $session, string $clientKey, string $summary, ?string $detail): array
    {
        return $this->send($session, 'complaint', $clientKey, null, $summary, $detail);
    }

    /**
     * The bill so far.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function bill(array $session): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];
        $base = ['hotel' => $this->profile->nameOf($property) ?? '', 'label' => $session['label'], 'verified' => $this->proved($session), 'locked' => $session['locked'], 'bill' => null];

        if (! $this->proved($session)) {
            return $base;
        }

        $stay = $this->desk->stay($property, (string) $session['stay_id']);
        $bill = $this->desk->runningBill($property, (string) $session['stay_id']);

        return [...$base, 'bill' => $bill === null || $stay === null || ! $stay['in_house'] ? null : $bill, 'departure' => $stay['expected_departure'] ?? null];
    }

    /**
     * The survey: whether it is open, and whether it was answered.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function survey(array $session): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];
        $base = ['hotel' => $this->profile->nameOf($property) ?? '', 'label' => $session['label'], 'verified' => $this->proved($session), 'locked' => $session['locked'], 'open' => false, 'answered' => false, 'departure' => null];

        if (! $this->proved($session)) {
            return $base;
        }

        $stayId = (string) $session['stay_id'];
        $stay = $this->desk->stay($property, $stayId);

        if ($stay === null || ! $stay['in_house']) {
            return $base;
        }

        return [...$base, 'open' => $this->surveyOpen($property, $stay['expected_departure']), 'answered' => $this->store->surveyOfStay($property, $stayId) !== null, 'departure' => $stay['expected_departure']];
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, int|null>  $ratings  `overall` and the optional ones of {@see self::SURVEY_FIELDS}
     * @return array<string, mixed> the survey state after the answer
     */
    public function answer(array $session, array $ratings, ?string $comment): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];
        $this->requireProof($session);
        $stayId = (string) $session['stay_id'];
        $stay = $this->desk->stay($property, $stayId);

        if ($stay === null || ! $stay['in_house'] || ! $this->surveyOpen($property, $stay['expected_departure'])) {
            throw Refusal::stateConflict('The survey opens close to the day you leave.');
        }

        $overall = $ratings['overall'] ?? null;

        if (! is_int($overall) || $overall < 1 || $overall > 5) {
            throw Refusal::invalid('Give an overall rating from 1 to 5.', ['overall']);
        }

        $row = ['id' => $this->ids->next(), 'stay_id' => $stayId, 'reservation_id' => $stay['reservation_id'], 'session_id' => $session['id'], 'overall' => $overall, 'complaint_id' => null];

        foreach (self::SURVEY_FIELDS as $field) {
            $v = $ratings[$field] ?? null;

            if ($v !== null && (! is_int($v) || $v < 1 || $v > 5)) {
                throw Refusal::invalid('Each rating is from 1 to 5.', [$field]);
            }

            $row[$field] = $v;
        }

        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        if ($comment !== null && mb_strlen($comment) > 500) {
            throw Refusal::invalid('The comment is at most 500 characters.', ['comment']);
        }

        $row['comment'] = $comment;

        $this->transactions->run(function () use ($property, $stayId, $row, $overall, $comment): void {
            if ($this->store->surveyOfStay($property, $stayId) !== null) {
                throw Refusal::stateConflict('You have answered the survey already. Thank you.');
            }

            if ($overall <= (int) config('guest.survey_complaint_at_or_below')) {
                $summary = 'Low rating in the guest survey ('.$overall.'/5)';
                $row['complaint_id'] = $this->desk->recordComplaint($property, $stayId, $summary, $comment, 'survey-'.$stayId)['id'];
            }

            $this->store->addSurvey($property, $row, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), null, 'guest_survey.answered', 'guest_survey', $row['id'], null, ['overall' => $overall, 'complaint' => $row['complaint_id'] !== null]));
            $this->outbox->publish(new OutboxEvent($property, 'guest.survey.answered', $row['id'], 1, ['survey_id' => $row['id'], 'overall' => $overall, 'complaint_opened' => $row['complaint_id'] !== null]));
        });

        return $this->survey($session);
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function send(array $session, string $kind, string $clientKey, ?string $category, string $title, ?string $detail): array
    {
        /** @var PropertyId $property */
        $property = $session['property'];
        $this->requireProof($session);

        if (preg_match('/^[A-Za-z0-9_-]{16,40}$/D', $clientKey) !== 1) {
            throw Refusal::invalid('This has no key; reload the page and try again.', ['client_key']);
        }

        if ($this->store->requestByKey($property, $session['id'], $clientKey) !== null) {
            return $this->help($session);
        }

        $title = trim($title);
        $detail = $detail === null || trim($detail) === '' ? null : trim($detail);

        if ($title === '' || mb_strlen($title) > 120 || ($detail !== null && mb_strlen($detail) > 500)) {
            throw Refusal::invalid('Say what you need in at most 120 characters, with details of at most 500.', ['title']);
        }

        if ($kind === 'request' && ! in_array($category, GuestStayDesk::CATEGORIES, true)) {
            throw Refusal::invalid('Choose who should help you.', ['category']);
        }

        if ($this->store->requestsSince($property, $session['id'], $this->clock->nowUtc()->modify('-1 hour')) >= (int) config('guest.requests_per_hour')) {
            throw Refusal::stateConflict('Too many requests in the last hour. Please call the front desk.');
        }

        $stayId = (string) $session['stay_id'];

        $this->transactions->run(function () use ($property, $session, $kind, $clientKey, $category, $title, $detail, $stayId): void {
            $ref = $kind === 'request'
                ? $this->desk->openRequest($property, $stayId, (string) $category, $title, $detail, $clientKey)
                : $this->desk->recordComplaint($property, $stayId, $title, $detail, $clientKey);
            $id = $this->ids->next();
            $this->store->addRequest($property, ['id' => $id, 'session_id' => $session['id'], 'stay_id' => $stayId, 'kind' => $kind, 'category' => $category, 'ref_id' => $ref['id'], 'ref_number' => $ref['number'], 'title' => mb_substr($title, 0, 150), 'client_key' => $clientKey], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), null, 'guest_help.sent', 'guest_help', $id, null, ['kind' => $kind, 'category' => $category, 'number' => $ref['number'], 'code' => $session['label']]));
            $this->outbox->publish(new OutboxEvent($property, 'guest.help.sent', $id, 1, ['help_id' => $id, 'kind' => $kind, 'category' => $category, 'number' => $ref['number']]));
        });

        return $this->help($session);
    }

    private function surveyOpen(PropertyId $property, string $departure): bool
    {
        $today = $this->businessDate->current($property)->toString();
        $opens = (new DateTimeImmutable($departure))->modify('-'.(int) config('guest.survey_days_before').' days')->format('Y-m-d');

        return $today >= $opens && $today <= $departure;
    }

    /** @param array<string, mixed> $session */
    private function proved(array $session): bool
    {
        return (bool) $session['verified'] && $session['stay_id'] !== null;
    }

    /** @param array<string, mixed> $session */
    private function requireProof(array $session): void
    {
        if (! $this->proved($session)) {
            throw Refusal::forbidden('Confirm your room number and name first.');
        }
    }
}
