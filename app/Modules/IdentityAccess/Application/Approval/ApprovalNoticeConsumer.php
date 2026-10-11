<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Notifications\PushNotifier;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffContacts;
use App\Shared\Application\Security\StaffDirectory;
use Throwable;

/**
 * Tells the people who may decide the open step of an approval, by e-mail, that something waits for them. The message names nothing (no subject, no amount, no maker): it says that an approval waits and where
 * to look, behind the person's own sign-in. It goes by e-mail only when `APPROVAL_EMAIL_NOTICE` is on and, to devices that allowed notices, whenever web push is set up (docs/OPERATIONS/WEB-PUSH.md), only to people who hold the step's permission for the whole property, never to the maker or to someone who already
 * decided, and a person with no e-mail address or a mailer that fails never holds anything up: the request is in the approvals list either way. Approvers limited to one outlet are not written to.
 */
final readonly class ApprovalNoticeConsumer implements OutboxConsumer
{
    public function __construct(private ApprovalRepository $requests, private StaffDirectory $staff, private StaffContacts $contacts, private EmailNotifier $mail, private PushNotifier $push) {}

    public function name(): string
    {
        return 'identity.approval-notice';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === 'identity.approval.awaiting';
    }

    public function consume(OutboxMessage $message): void
    {
        $byEmail = (bool) config('identity_access.approval_email_notice');

        if (! $byEmail && ! $this->push->enabled()) {
            return;
        }

        $event = $message->event;
        $request = $this->requests->find($event->propertyId, strtolower((string) ($event->data['request_id'] ?? '')));
        $permission = $request?->currentStepPermission();

        if ($request === null || $request->status()->isFinal() || $permission === null) {
            return;
        }

        $skip = [$request->makerId];

        foreach ($request->decisions() as $decision) {
            $skip[] = strtolower((string) $decision->approverId);
        }

        $ids = array_values(array_diff(array_map(static fn (array $p): string => strtolower($p['id']), $this->staff->withPermission($event->propertyId, $permission)), $skip));

        if ($ids === []) {
            return;
        }

        // On the devices of those who allowed it: the same plain words, a path of this application as the place to open.
        $this->push->notify($event->propertyId, $ids, 'Persetujuan menunggu / Approval waiting', 'Ada permintaan persetujuan yang menunggu keputusan Anda. / An approval request is waiting for your decision.', '/approvals');

        if (! $byEmail) {
            return;
        }

        $url = rtrim((string) config('app.url'), '/').'/approvals';

        foreach ($this->contacts->emailsOf($event->propertyId, $ids) as $address) {
            try {
                $this->mail->notify(
                    $address,
                    'Ada persetujuan menunggu Anda / An approval is waiting for you',
                    "Ada permintaan persetujuan yang menunggu keputusan Anda. Masuk lalu buka {$url}.\n\nAn approval request is waiting for your decision. Sign in and open {$url}.",
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
