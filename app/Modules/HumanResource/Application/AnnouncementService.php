<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The notice board of the staff and the policies they must read (FR-HR-024). A notice or a policy goes to everyone or to one department, can carry a document, and can expire. Opening it is noted for the person; a policy that
 * asks for it needs the person to confirm they read and understood it, once, and the confirmation is never changed. The people with the right see how many read and confirmed and who has not. What is published is not edited:
 * it is withdrawn with a reason and a new one is published, so who read what stays true.
 */
final readonly class AnnouncementService
{
    public const KINDS = ['announcement', 'policy'];

    public const MAX_BYTES = 5_242_880;

    public function __construct(
        private AnnouncementStore $store,
        private EmployeeStore $employees,
        private HrAccess $access,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();
        $manage = $this->access->may($property, $actorId, HrAccess::ANNOUNCE);
        $meId = $this->employees->employeeOfUser($property, $actor);
        $me = $meId === null ? null : $this->employees->employee($property, $meId);
        $all = $this->store->list($property, $manage, 200);
        $reads = $this->store->reads($property, array_column($all, 'id'));
        $active = $this->employees->employees($property, 'active');

        $feed = [];

        if ($me !== null && $me['status'] === 'active') {
            foreach ($all as $a) {
                if ($a['status'] === 'published' && ($a['expires_on'] === null || (string) $a['expires_on'] >= $today) && ($a['audience'] === 'all' || $a['audience'] === $me['department'])) {
                    $r = $reads[$a['id']][$me['id']] ?? null;
                    $feed[] = [...self::shape($a), 'read' => $r !== null, 'acknowledged' => $r !== null && $r['acknowledged_at'] !== null];
                }
            }
        }

        $board = null;

        if ($manage) {
            $board = array_map(static function (array $a) use ($reads, $active): array {
                $audience = array_values(array_filter($active, static fn (array $e): bool => $a['audience'] === 'all' || $e['department'] === $a['audience']));
                $mine = $reads[$a['id']] ?? [];
                $pending = $a['requires_ack'] ? array_values(array_filter($audience, static fn (array $e): bool => ($mine[$e['id']]['acknowledged_at'] ?? null) === null)) : [];

                return [...self::shape($a), 'audience_size' => count($audience), 'read' => count(array_filter($audience, static fn (array $e): bool => isset($mine[$e['id']]))), 'acknowledged' => count(array_filter($audience, static fn (array $e): bool => ($mine[$e['id']]['acknowledged_at'] ?? null) !== null)),
                    'pending' => array_map(static fn (array $e): array => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name']], array_slice($pending, 0, 50)), 'withdraw_reason' => $a['withdraw_reason']];
            }, $all);
        }

        return ['today' => $today, 'linked' => $me !== null, 'may' => ['manage' => $manage], 'kinds' => self::KINDS, 'departments' => EmployeeService::DEPARTMENTS, 'feed' => $feed, 'board' => $board];
    }

    /** @return array<string, mixed> */
    public function publish(PropertyId $property, string $actorId, string $kind, string $title, string $body, string $audience, bool $requiresAck, ?string $expiresOn, ?string $document, ?string $documentName): array
    {
        $this->access->require($property, $actorId, HrAccess::ANNOUNCE, 'This person may not publish to the staff.');
        $actor = strtolower($actorId);
        $title = trim($title);
        $body = trim($body);
        $today = $this->businessDate->current($property)->toString();

        if (! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Choose a notice or a policy.', ['kind']);
        }

        if ($title === '' || mb_strlen($title) > 120 || $body === '' || mb_strlen($body) > 4000) {
            throw Refusal::invalid('Give a title of at most 120 characters and a text of at most 4000.', ['title', 'body']);
        }

        if ($audience !== 'all' && ! in_array($audience, EmployeeService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose everyone or a department of the list.', ['audience']);
        }

        if ($expiresOn !== null && $expiresOn !== '' && (! ShiftTimes::isDate($expiresOn) || $expiresOn < $today)) {
            throw Refusal::invalid('A notice expires today or later.', ['expires_on']);
        }

        $id = $this->ids->next();
        $file = null;

        if ($document !== null && $document !== '') {
            try {
                $file = $this->storeFile->execute(new FileUpload($property, $actor, DocumentService::PURPOSE, 'announcement', $id, $document, new FilePolicy(['application/pdf', 'image/jpeg', 'image/png'], self::MAX_BYTES, FileSensitivity::Standard, false), $documentName));
            } catch (FileRejected $e) {
                throw Refusal::invalid($e->getMessage(), ['document']);
            }
        }

        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $id, $kind, $title, $body, $audience, $requiresAck, $expiresOn, $file, $now): void {
            $this->store->add($property, ['id' => $id, 'kind' => $kind, 'title' => $title, 'body' => $body, 'audience' => $audience, 'requires_ack' => $requiresAck, 'expires_on' => $expiresOn === '' ? null : $expiresOn, 'file_id' => $file?->id, 'status' => 'published', 'published_by' => $actor, 'published_at' => $now->format('Y-m-d H:i:s.u')], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'announcement.published', 'announcement', $id, null, ['kind' => $kind, 'title' => $title, 'audience' => $audience, 'requires_ack' => $requiresAck, 'document' => $file !== null]));
            $this->outbox->publish(new OutboxEvent($property, 'hr.announcement.published', $id, 1, ['announcement_id' => $id, 'kind' => $kind, 'audience' => $audience, 'requires_ack' => $requiresAck]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function withdraw(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::ANNOUNCE, 'This person may not publish to the staff.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $reason, $lock): void {
            $a = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Announcement not found.');

            if ($a['status'] !== 'published') {
                throw Refusal::stateConflict('This was withdrawn already.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->update($property, $a['id'], $lock, ['status' => 'withdrawn', 'withdraw_reason' => $reason], $now)) {
                throw Refusal::stateConflict('This changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'announcement.withdrawn', 'announcement', $a['id'], ['status' => 'published'], ['status' => 'withdrawn', 'title' => $a['title']], $reason));
        });

        return $this->overview($property, $actorId);
    }

    /** The person opened it. @return array<string, mixed> */
    public function read(PropertyId $property, string $actorId, string $id): array
    {
        [$a, $employee] = $this->forReader($property, $actorId, $id);
        $this->store->markRead($property, $a['id'], $employee['id'], $this->clock->nowUtc());

        return $this->overview($property, $actorId);
    }

    /** The person confirms they read and understood it. @return array<string, mixed> */
    public function acknowledge(PropertyId $property, string $actorId, string $id): array
    {
        [$a, $employee] = $this->forReader($property, $actorId, $id);

        if (! (bool) $a['requires_ack']) {
            throw Refusal::stateConflict('This notice does not ask for a confirmation.');
        }

        $this->transactions->run(function () use ($property, $a, $employee, $actorId): void {
            $now = $this->clock->nowUtc();
            $this->store->markRead($property, $a['id'], $employee['id'], $now);

            if ($this->store->acknowledge($property, $a['id'], $employee['id'], $now)) {
                $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'announcement.acknowledged', 'announcement', $a['id'], null, ['title' => $a['title'], 'employee' => $employee['number']]));
            }
        });

        return $this->overview($property, $actorId);
    }

    public function document(PropertyId $property, string $actorId, string $id): FileContent
    {
        [$a] = $this->forReader($property, $actorId, $id, true);

        if ($a['file_id'] === null) {
            throw Refusal::notFound('This has no document.');
        }

        $policy = new class implements FileAccessPolicy
        {
            public function allows(string $actorId, StoredFile $file): bool
            {
                return true;
            }
        };

        try {
            return $this->downloadFile->execute($property, (string) $a['file_id'], strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The document is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not open the document.');
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function forReader(PropertyId $property, string $actorId, string $id, bool $managerToo = false): array
    {
        $this->access->assertProperty($property);
        $a = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Announcement not found.');
        $meId = $this->employees->employeeOfUser($property, strtolower($actorId));
        $employee = $meId === null ? null : $this->employees->employee($property, $meId);

        if ($managerToo && $this->access->may($property, $actorId, HrAccess::ANNOUNCE)) {
            return [$a, $employee ?? []];
        }

        if ($employee === null || $employee['status'] !== 'active') {
            throw Refusal::forbidden('Your account is not linked to an employee record.');
        }

        $today = $this->businessDate->current($property)->toString();

        if ($a['status'] !== 'published' || ($a['expires_on'] !== null && (string) $a['expires_on'] < $today) || ($a['audience'] !== 'all' && $a['audience'] !== $employee['department'])) {
            throw Refusal::notFound('Announcement not found.');
        }

        return [$a, $employee];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private static function shape(array $a): array
    {
        return [
            'id' => $a['id'], 'kind' => $a['kind'], 'title' => $a['title'], 'body' => $a['body'], 'audience' => $a['audience'], 'requires_ack' => (bool) $a['requires_ack'], 'expires_on' => $a['expires_on'] === null ? null : substr((string) $a['expires_on'], 0, 10),
            'has_document' => $a['file_id'] !== null, 'status' => $a['status'], 'published_at' => (new \DateTimeImmutable((string) $a['published_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'lock_version' => (int) $a['lock_version'],
        ];
    }
}
