<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

use DateTimeImmutable;

/** Read model of a request, safe to hand to modules and screens. */
final readonly class ApprovalView
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $before
     * @param  list<array{step: int, approver_id: string, decision: string, reason: ?string, decided_at: string}>  $decisions
     * @param  list<array{permission: string, approvals_required: int}>  $steps
     */
    public function __construct(
        public string $id,
        public string $propertyId,
        public string $subjectType,
        public string $subjectRef,
        public string $makerId,
        public string $reason,
        public string $status,
        public int $currentStep,
        public array $steps,
        public array $decisions,
        public array $payload,
        public ?array $before,
        public ?int $amountMinor,
        public ?string $currency,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
        public bool $consumed,
    ) {}

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subjectType,
            'subject_ref' => $this->subjectRef,
            'maker_id' => $this->makerId,
            'reason' => $this->reason,
            'status' => $this->status,
            'current_step' => $this->currentStep,
            'steps' => $this->steps,
            'decisions' => $this->decisions,
            'payload' => $this->payload,
            'before' => $this->before,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s.u\Z'),
            'completed_at' => $this->completedAt?->format('Y-m-d\TH:i:s.u\Z'),
            'consumed' => $this->consumed,
        ];
    }
}
