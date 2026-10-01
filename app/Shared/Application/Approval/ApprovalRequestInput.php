<?php

declare(strict_types=1);

namespace App\Shared\Application\Approval;

use App\Shared\Application\Observability\SensitiveDataGuard;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

/**
 * What a module asks to have approved. `payload` is the exact change (it is
 * hashed and shown to approvers); `before` is the current state, for evidence.
 * Both are stored, so they must hold no secrets, card data or identity documents.
 */
final readonly class ApprovalRequestInput
{
    public const SUBJECT_PATTERN = '/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+){1,6}$/D';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $before
     * @param  ?string  $scopeType  `outlet` or `department` when approvers must hold the permission for that resource
     */
    public function __construct(
        public PropertyId $propertyId,
        public string $subjectType,
        public string $subjectRef,
        public string $makerId,
        public string $reason,
        public array $payload,
        public ?array $before = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $scopeType = null,
        public ?string $scopeId = null,
        public ?string $supersedes = null,
    ) {
        if (preg_match(self::SUBJECT_PATTERN, $subjectType) !== 1 || strlen($subjectType) > 120) {
            throw new InvalidArgumentException('The approval subject type must look like module.action.');
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $subjectRef) !== 1) {
            throw new InvalidArgumentException('The approval subject reference must be a short opaque identifier.');
        }

        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/Di', $makerId) !== 1) {
            throw new InvalidArgumentException('The maker must be a user ULID.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A request needs a reason of at most 500 characters.');
        }

        if ($amountMinor !== null && $amountMinor < 0) {
            throw new InvalidArgumentException('An amount in minor units cannot be negative.');
        }

        if ($currency !== null && preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('The currency must be an ISO 4217 code.');
        }

        if (($scopeType === null) !== ($scopeId === null) || ($scopeType !== null && ! in_array($scopeType, ['outlet', 'department'], true))) {
            throw new InvalidArgumentException('A scope needs a type (outlet or department) and an id together.');
        }

        SensitiveDataGuard::assertSafe($payload);
        SensitiveDataGuard::assertSafe($before ?? []);

        if (strlen((string) json_encode([$payload, $before])) > 32768) {
            throw new InvalidArgumentException('The approval evidence is too large.');
        }
    }

    public function payloadHash(): string
    {
        return ApprovalPayloadHash::of($this->payload);
    }
}
