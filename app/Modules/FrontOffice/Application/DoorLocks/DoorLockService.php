<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\DoorLocks;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Door locks that follow the stay (key cards or codes made at check-in, taken back at check-out, moved with a room move, given a new end date when the stay is extended). InnSYnc keeps the list of jobs and the
 * settings; a small program beside the lock system (on the hotel's network) fetches the jobs with a token and reports what it did. The token is shown once when it is made and only its hash is kept. A job that failed
 * is tried again by a person, or settled by hand when the key was made at the lock station.
 */
final readonly class DoorLockService
{
    public const MANAGE_PERMISSION = 'front-office.door-lock.manage';

    /** The makes of lock this hotel group is likely to meet. The bridge speaks one plain format whatever the make; the name is for the screen and the person who sets up the bridge. */
    public const VENDORS = ['visionline', 'onity', 'salto', 'dormakaba', 'hafele', 'other'];

    public function __construct(private DoorLockStore $store, private PermissionChecker $permissions, private PropertyContext $context, private TransactionRunner $transactions, private AuditTrail $audit, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->require($property, $actorId);
        $s = $this->store->settings($property);

        return [
            'settings' => $s ?? ['enabled' => false, 'vendor' => 'other', 'key_count' => 2, 'token_hint' => null, 'lock_version' => null],
            'vendors' => self::VENDORS, 'counts' => $this->store->counts($property),
            'jobs' => array_map(static fn (array $j): array => [
                'id' => $j['id'], 'kind' => $j['kind'], 'room_number' => $j['room_number'], 'reservation_number' => $j['reservation_number'], 'key_count' => (int) $j['key_count'], 'valid_until' => $j['valid_until'] === null ? null : substr((string) $j['valid_until'], 0, 10),
                'status' => $j['status'], 'attempts' => (int) $j['attempts'], 'message' => $j['message'], 'created_at' => (string) $j['created_at'],
            ], $this->store->jobs($property, 100)),
        ];
    }

    /** @return array<string, mixed> */
    public function saveSettings(PropertyId $property, string $actorId, bool $enabled, string $vendor, int $keyCount, ?int $lock): array
    {
        $this->require($property, $actorId);

        if (! in_array($vendor, self::VENDORS, true)) {
            throw Refusal::invalid('Choose the make of the lock.', ['vendor']);
        }

        if ($keyCount < 1 || $keyCount > 6) {
            throw Refusal::invalid('Keys per stay are 1 to 6.', ['key_count']);
        }

        $this->transactions->run(function () use ($property, $actorId, $enabled, $vendor, $keyCount, $lock): void {
            $before = $this->store->settings($property);

            if (($before === null ? null : $before['lock_version']) !== $lock || ! $this->store->saveSettings($property, $enabled, $vendor, $keyCount, $lock, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The door lock settings changed after you opened them. Reload.');
            }

            if ($enabled && ($before === null || $before['token_hint'] === null)) {
                throw Refusal::stateConflict('Make the bridge token before switching door locks on.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'door_lock.settings_saved', 'door_lock_settings', $property->toString(), $before === null ? null : ['enabled' => $before['enabled'], 'vendor' => $before['vendor'], 'key_count' => $before['key_count']], ['enabled' => $enabled, 'vendor' => $vendor, 'key_count' => $keyCount]));
        });

        return $this->overview($property, $actorId);
    }

    /** Makes a new token (the old one stops working) and returns it once. @return array{token: string} */
    public function newToken(PropertyId $property, string $actorId): array
    {
        $this->require($property, $actorId);

        if ($this->store->settings($property) === null) {
            $this->store->saveSettings($property, false, 'other', 2, null, strtolower($actorId), $this->clock->nowUtc());
        }

        $secret = bin2hex(random_bytes(24));
        $token = $property->toString().'.'.$secret;

        $this->transactions->run(function () use ($property, $actorId, $secret): void {
            $this->store->saveToken($property, hash('sha256', $secret), substr($secret, -4), strtolower($actorId), $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'door_lock.token_made', 'door_lock_settings', $property->toString(), null, ['hint' => substr($secret, -4)]));
        });

        return ['token' => $token];
    }

    /** Tries a failed job again. */
    public function retry(PropertyId $property, string $actorId, string $jobId): void
    {
        $this->require($property, $actorId);
        $job = $this->store->job($property, strtolower($jobId)) ?? throw Refusal::notFound('Job not found.');

        if (! in_array($job['status'], ['failed', 'sent'], true)) {
            throw Refusal::stateConflict('Only a job that failed or got no answer is tried again.');
        }

        $this->transactions->run(function () use ($property, $actorId, $job): void {
            if (! $this->store->requeue($property, $job['id'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This job changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'door_lock.job_retried', 'door_lock_job', $job['id'], ['status' => $job['status']], ['status' => 'pending']));
        });
    }

    /** Settles a job by hand: the key was made or taken back at the lock station. */
    public function settle(PropertyId $property, string $actorId, string $jobId, string $reason): void
    {
        $this->require($property, $actorId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say what was done, in at most 200 characters.', ['reason']);
        }

        $job = $this->store->job($property, strtolower($jobId)) ?? throw Refusal::notFound('Job not found.');

        if (! in_array($job['status'], ['pending', 'sent', 'failed'], true)) {
            throw Refusal::stateConflict('This job is settled already.');
        }

        $this->transactions->run(function () use ($property, $actorId, $job, $reason): void {
            if (! $this->store->finish($property, $job['id'], 'manual', $reason, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This job changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'door_lock.job_settled', 'door_lock_job', $job['id'], ['status' => $job['status']], ['status' => 'manual'], $reason));
        });
    }

    private function require(PropertyId $property, string $actorId): void
    {
        $current = $this->context->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not set up door locks.');
        }
    }
}
