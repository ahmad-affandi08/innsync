<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use DateTimeImmutable;

/** A request from a person about their personal data (UU 27/2022). Immutable; every change returns a new value. */
final readonly class DataSubjectRequest
{
    public const ACCESS = 'access';

    public const CORRECTION = 'correction';

    public const DELETION = 'deletion';

    public const WITHDRAW_CONSENT = 'withdraw_consent';

    public const OBJECTION = 'objection';

    public const TYPES = [self::ACCESS, self::CORRECTION, self::DELETION, self::WITHDRAW_CONSENT, self::OBJECTION];

    public const RECEIVED = 'received';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const REFUSED = 'refused';

    public function __construct(
        public string $id,
        public string $subjectType,
        public string $subjectId,
        public string $type,
        public string $status,
        public string $channel,
        public string $verificationNote,
        public DateTimeImmutable $receivedAt,
        public DateTimeImmutable $dueAt,
        public ?string $decisionBasis,
        public ?string $decisionNote,
        public ?string $handledBy,
        public ?DateTimeImmutable $completedAt,
        public int $lockVersion,
        public string $createdBy,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === self::RECEIVED || $this->status === self::IN_PROGRESS;
    }

    public function isOverdueAt(DateTimeImmutable $now): bool
    {
        return $this->isOpen() && $this->dueAt < $now;
    }

    public function start(string $actorId): self
    {
        if ($this->status !== self::RECEIVED) {
            throw PrivacyRefused::stateConflict('Only a received request can be started.');
        }

        return $this->with(self::IN_PROGRESS, $this->decisionBasis, $this->decisionNote, $actorId, null);
    }

    public function complete(string $actorId, string $note, DateTimeImmutable $at): self
    {
        $this->assertOpen();

        if (trim($note) === '' || mb_strlen($note) > 500) {
            throw PrivacyRefused::invalid('Completing a request needs a note of at most 500 characters on what was done.', ['decision_note']);
        }

        return $this->with(self::COMPLETED, $this->decisionBasis, trim($note), $actorId, $at);
    }

    /** A refusal states the legal basis (for example a statutory retention period) and what the person is told. */
    public function refuse(string $actorId, string $basis, string $note, DateTimeImmutable $at): self
    {
        $this->assertOpen();

        if (trim($basis) === '' || mb_strlen($basis) > 500 || trim($note) === '' || mb_strlen($note) > 500) {
            throw PrivacyRefused::invalid('Refusing a request needs its legal basis and an explanation, each at most 500 characters.', ['decision_basis', 'decision_note']);
        }

        return $this->with(self::REFUSED, trim($basis), trim($note), $actorId, $at);
    }

    private function assertOpen(): void
    {
        if (! $this->isOpen()) {
            throw PrivacyRefused::stateConflict('This request was already decided.');
        }
    }

    private function with(string $status, ?string $basis, ?string $note, string $actorId, ?DateTimeImmutable $completedAt): self
    {
        return new self(
            $this->id, $this->subjectType, $this->subjectId, $this->type, $status, $this->channel, $this->verificationNote,
            $this->receivedAt, $this->dueAt, $basis, $note, strtolower($actorId), $completedAt, $this->lockVersion, $this->createdBy,
        );
    }
}
