<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Offline\ConflictAction;
use App\Shared\Application\Offline\OfflineContext;
use App\Shared\Application\Offline\OfflineEnvelope;
use App\Shared\Application\Offline\OfflineOperationHandler;
use App\Shared\Application\Offline\OfflineOutcome;

/**
 * An attendant's start or finish of a room, recorded on the phone while the network was down (NFR-04). The device keeps it in its encrypted queue under an operation ID and in the order it was
 * made; when the network is back the foundation applies it once, as the attendant who made it, through the same service as the online screen, so the room moves exactly as it would have then.
 *
 * The change is based on the version of the task the phone had. If the task changed meanwhile (a supervisor reassigned or cancelled it, someone else started it, the guest put up a
 * do-not-disturb) the step is not forced: it is returned as a conflict for the supervisor, and the room stays as it is.
 */
final readonly class OfflineTaskHandler implements OfflineOperationHandler
{
    public const TYPE = 'hk.task.progress';

    public function __construct(private HousekeepingService $housekeeping) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function payloadVersions(): array
    {
        return [1];
    }

    public function permission(): ?string
    {
        return HousekeepingService::PERFORM_PERMISSION;
    }

    public function handle(OfflineEnvelope $envelope, OfflineContext $context): OfflineOutcome
    {
        $taskId = $envelope->payload['task_id'] ?? null;
        $step = $envelope->payload['step'] ?? null;

        if (! is_string($taskId) || preg_match('/^[0-9a-z]{26}$/i', $taskId) !== 1 || ! in_array($step, ['start', 'finish'], true) || $envelope->baseVersion === null || $envelope->baseVersion < 0) {
            return OfflineOutcome::rejected('invalid_payload');
        }

        try {
            $task = $step === 'start'
                ? $this->housekeeping->start($context->propertyId, $context->actorId, $taskId, $envelope->baseVersion)
                : $this->housekeeping->finish($context->propertyId, $context->actorId, $taskId, $envelope->baseVersion);
        } catch (Refusal $refusal) {
            $message = $refusal->getMessage();

            return match (true) {
                $refusal->status() === 403 => OfflineOutcome::rejected('forbidden'),
                $refusal->status() === 404 => OfflineOutcome::rejected('not_found'),
                $refusal->status() === 422 => OfflineOutcome::rejected('invalid_step'),
                str_contains($message, 'not to be disturbed') || str_contains($message, 'asked for privacy') => OfflineOutcome::conflict('guest_flag', ConflictAction::Review),
                str_contains($message, 'changed after') => OfflineOutcome::conflict('task_changed', ConflictAction::Refresh),
                default => OfflineOutcome::conflict('task_state', ConflictAction::Review),
            };
        }

        return OfflineOutcome::accepted(['step' => $step, 'status' => (string) ($task['status'] ?? '')], (int) ($task['lock_version'] ?? 0));
    }
}
