<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\SystemActors;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;

/**
 * Puts the commission of a finished spa treatment on the therapist's own commission record (kind SPA), so "My commissions" shows it with the rest without anyone typing it. The amount is the one the spa fixed when the
 * treatment was finished; nothing is worked out here. A therapist whose account is not linked to an employee record gets nothing here (the spa still reports it), and the same treatment is recorded once however often the
 * event is delivered.
 */
final readonly class SpaCommissionConsumer implements OutboxConsumer
{
    public const EVENT = 'frontoffice.spa.treatment_done';

    public const TYPE_CODE = 'SPA';

    public function __construct(
        private EmployeeCommissionStore $store,
        private EmployeeStore $employees,
        private SystemActors $actors,
        private IdentifierGenerator $ids,
        private PropertyContext $context,
        private Clock $clock,
    ) {}

    public function name(): string
    {
        return 'hr.spa-commission';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT;
    }

    public function consume(OutboxMessage $message): void
    {
        $property = $message->event->propertyId;
        $d = $message->event->data;
        $amount = (int) ($d['commission_minor'] ?? 0);

        if ($amount < 1) {
            return;
        }

        $this->context->run($property, function () use ($property, $d, $amount): void {
            $employeeId = $this->employees->employeeOfUser($property, strtolower((string) $d['therapist_user_id']));

            if ($employeeId === null) {
                return;
            }

            $now = $this->clock->nowUtc();
            $type = $this->store->typeByCode($property, self::TYPE_CODE);

            if ($type === null) {
                $this->store->addType($property, ['id' => $this->ids->next(), 'code' => self::TYPE_CODE, 'name' => 'Spa treatment', 'is_active' => true], $now);
                $type = $this->store->typeByCode($property, self::TYPE_CODE);
            }

            if ($type === null) {
                return;
            }

            // Once per booking: the unique source makes a second delivery a no-op.
            $this->store->addEntry($property, [
                'id' => $this->ids->next(), 'employee_id' => $employeeId, 'type_id' => $type['id'], 'earned_on' => (string) $d['earned_on'], 'amount_minor' => $amount,
                'note' => mb_substr('Spa '.(string) $d['number'].' · '.(string) ($d['treatment'] ?? ''), 0, 200), 'reverses_id' => null, 'source_ref' => strtolower((string) $d['booking_id']),
                'entered_by' => $this->actors->automation($property, []),
            ], $now);
        });
    }
}
