<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\DoorLocks;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

/**
 * What the program beside the lock system may do, with the token of the property and nothing else: fetch the jobs that wait (a job fetched and never answered comes again after five minutes) and report, for each,
 * whether the key was made or taken back. A report is accepted once; the same report sent again changes nothing. The program is told nothing about the guest: the room, the reservation number, the number of keys
 * and the last day the key works.
 */
final readonly class DoorLockBridge
{
    private const LEASE_SECONDS = 300;

    public function __construct(private DoorLockStore $store, private PropertyContext $context, private TransactionRunner $transactions, private AuditTrail $audit, private Clock $clock) {}

    /** The property the token belongs to, or null (also when door locks are switched off). */
    public function authenticate(?string $token): ?PropertyId
    {
        if ($token === null || preg_match('/^([0-9a-z]{26})\.([0-9a-f]{48})$/', strtolower($token), $m) !== 1) {
            return null;
        }

        try {
            $property = $this->store->propertyOfToken($m[1], hash('sha256', $m[2]));
        } catch (InvalidArgumentException) {
            return null;
        }

        return $property !== null && ($this->store->settings($property)['enabled'] ?? false) ? $property : null;
    }

    /** @return list<array{id: string, kind: string, room: string, reservation: string|null, keys: int, valid_until: string|null}> */
    public function pull(PropertyId $property, int $limit): array
    {
        $jobs = $this->transactions->run(fn (): array => $this->store->claim($property, max(1, min(50, $limit)), self::LEASE_SECONDS, $this->clock->nowUtc()));

        return array_map(static fn (array $j): array => [
            'id' => $j['id'], 'kind' => $j['kind'], 'room' => $j['room_number'], 'reservation' => $j['reservation_number'], 'keys' => (int) $j['key_count'], 'valid_until' => $j['valid_until'] === null ? null : substr((string) $j['valid_until'], 0, 10),
        ], $jobs);
    }

    public function report(PropertyId $property, string $jobId, string $outcome, ?string $message): void
    {
        if (! in_array($outcome, ['done', 'failed'], true)) {
            throw Refusal::invalid('The outcome is done or failed.', ['outcome']);
        }

        $message = $message === null || trim($message) === '' ? null : mb_substr(trim($message), 0, 200);
        $job = $this->store->job($property, strtolower($jobId)) ?? throw Refusal::notFound('Job not found.');

        if (in_array($job['status'], ['done', 'manual'], true)) {
            return;
        }

        // The bridge call has no session; the audit trail records for the property in force, which is the one the token belongs to.
        $this->context->run($property, fn () => $this->transactions->run(function () use ($property, $job, $outcome, $message): void {
            if ($this->store->finish($property, $job['id'], $outcome, $message, null, $this->clock->nowUtc())) {
                $this->audit->record(new AuditEntry($property->toString(), null, 'door_lock.job_'.$outcome, 'door_lock_job', $job['id'], ['status' => $job['status']], ['status' => $outcome, 'kind' => $job['kind'], 'room' => $job['room_number']], $message));
            }
        }));
    }
}
