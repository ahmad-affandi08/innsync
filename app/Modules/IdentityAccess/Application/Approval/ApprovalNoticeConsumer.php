<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Approval;

use App\Shared\Application\Notifications\EmailNotifier;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Security\StaffContacts;
use App\Shared\Application\Security\StaffDirectory;
use Throwable;

/**
 * Tells the people who may decide the open step of an approval, by e-mail, that something waits for them. The message names nothing (no subject, no amount, no maker): it says that an approval waits and where
 * to look, behind the person's own sign-in. It goes only when `APPROVAL_EMAIL_NOTICE` is on, only to people who hold the step's permission for the whole property, never to the maker or to someone who already
 * decided, and a person with no e-mail address or a mailer that fails never holds anything up: the request is in the approvals list either way. Approvers limited to one outlet are not written to.
 */
final readonly class ApprovalNoticeConsumer implements OutboxConsumer
{
    public function __construct(private ApprovalRepository $requests, private StaffDirectory $staff, private StaffContacts $contacts, private EmailNotifier $mail) {}

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
        if (! (bool) config('identity_access.approval_email_notice')) {
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
