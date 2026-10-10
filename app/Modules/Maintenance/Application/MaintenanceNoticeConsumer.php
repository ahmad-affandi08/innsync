<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffContacts;
use App\Shared\Application\Security\StaffDirectory;
use Throwable;

/**
 * Tells people by e-mail that a work order was given to them (the technician) or that one passed its warning or its deadline (the supervisor or the manager on duty, as the escalation matrix says).
 * It goes only when `MAINTENANCE_EMAIL_NOTICE` is on, names the work order number, its priority and the place, and nothing about a guest. A person with no e-mail address or a mailer that fails never holds
 * anything up: the work order and the escalation are on the screens either way.
 */
final readonly class MaintenanceNoticeConsumer implements OutboxConsumer
{
    public const ASSIGNED = 'maintenance.work_order.assigned';

    public const ESCALATED = 'maintenance.work_order.escalated';

    public function __construct(private StaffDirectory $staff, private StaffContacts $contacts, private EmailNotifier $mail) {}

    public function name(): string
    {
        return 'maintenance.notice';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::ASSIGNED || $eventType === self::ESCALATED;
    }

    public function consume(OutboxMessage $message): void
    {
        if (! (bool) config('maintenance.email_notice')) {
            return;
        }

        $event = $message->event;
        $d = $event->data;
        $what = sprintf('%s · %s · %s', (string) ($d['number'] ?? ''), (string) ($d['priority'] ?? ''), (string) ($d['place'] ?? '-'));
        $url = rtrim((string) config('app.url'), '/').'/maintenance/work-orders';

        if ($event->eventType === self::ASSIGNED) {
            $ids = [strtolower((string) ($d['technician_id'] ?? ''))];
            $subject = 'Pekerjaan perbaikan untuk Anda / A work order for you';
            $body = "Pekerjaan perbaikan diberikan kepada Anda: {$what}. Masuk lalu buka {$url}.\n\nA work order was given to you: {$what}. Sign in and open {$url}.";
        } else {
            $permission = ($d['target'] ?? 'supervisor') === 'mod' ? MaintenanceAccess::ESCALATION_RECEIVE : MaintenanceAccess::MANAGE;
            $ids = array_map(static fn (array $p): string => strtolower($p['id']), $this->staff->withPermission($event->propertyId, $permission));
            $level = (int) ($d['level'] ?? 1) >= 2 ? 'melewati batas waktu / is past its deadline' : 'hampir melewati batas waktu / is near its deadline';
            $subject = 'Perbaikan perlu perhatian / A work order needs attention';
            $body = "Pekerjaan perbaikan {$what} {$level}. Masuk lalu buka {$url}.\n\nWork order {$what} {$level}. Sign in and open {$url}.";
        }

        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => $id !== ''));

        if ($ids === []) {
            return;
        }

        foreach ($this->contacts->emailsOf($event->propertyId, $ids) as $address) {
            try {
                $this->mail->notify($address, $subject, $body);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
