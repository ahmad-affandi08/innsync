<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The privacy notice a guest agrees to before sending an identity document or a signature (FR-GST-007). The hotel writes each version in both languages; a new text is a new version and an old one is never
 * changed, because a consent refers to the words that were agreed to. Until the hotel writes one, a baseline text is shown and a consent to it is recorded as version 0 with the digest of those words.
 */
final readonly class PrivacyNoticeService
{
    public function __construct(
        private SelfCheckInStore $store,
        private GuestAccess $access,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array{version: int, body_id: string, body_en: string, digest: string} the notice in force */
    public function current(PropertyId $property): array
    {
        $row = $this->store->latestNotice($property);
        $id = $row === null ? (string) config('guest.checkin.privacy_baseline.id') : (string) $row['body_id'];
        $en = $row === null ? (string) config('guest.checkin.privacy_baseline.en') : (string) $row['body_en'];

        return ['version' => $row === null ? 0 : (int) $row['version'], 'body_id' => $id, 'body_en' => $en, 'digest' => hash('sha256', $id."\n".$en)];
    }

    /** @return array{notice: array{version: int, body_id: string, body_en: string, digest: string}, may_define: bool} */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, GuestAccess::CHECKIN_MANAGE) && ! $this->access->may($property, $actorId, GuestAccess::PRIVACY_MANAGE)) {
            throw Refusal::forbidden('This person may not see the privacy notice.');
        }

        return ['notice' => $this->current($property), 'may_define' => $this->access->may($property, $actorId, GuestAccess::PRIVACY_MANAGE)];
    }

    /** @return array{version: int, body_id: string, body_en: string, digest: string} the new version */
    public function define(PropertyId $property, string $actorId, string $bodyId, string $bodyEn, string $reason): array
    {
        $this->access->require($property, $actorId, GuestAccess::PRIVACY_MANAGE, 'This person may not write the privacy notice.');
        $bodyId = trim($bodyId);
        $bodyEn = trim($bodyEn);

        foreach (['body_id' => $bodyId, 'body_en' => $bodyEn] as $field => $text) {
            if (mb_strlen($text) < 40 || mb_strlen($text) > 4000) {
                throw Refusal::invalid('Write the notice in each language in 40 to 4,000 characters.', [$field]);
            }
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $bodyId, $bodyEn, $reason): void {
            $version = ((int) ($this->store->latestNotice($property)['version'] ?? 0)) + 1;

            if (! $this->store->addNotice($property, ['id' => $id, 'version' => $version, 'body_id' => $bodyId, 'body_en' => $bodyEn, 'reason' => trim($reason), 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The notice changed while you wrote it; try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest.privacy_notice.defined', 'guest_privacy_notice', $id, null, ['version' => $version, 'length_id' => mb_strlen($bodyId), 'length_en' => mb_strlen($bodyEn)], trim($reason)));
        });

        return $this->current($property);
    }
}
