<?php

declare(strict_types=1);

namespace App\Shared\Application\Errors;

/**
 * An outcome the system expects and explains, not a defect: it is never reported
 * as an error and becomes a stable response in the standard error envelope
 * (TASK-FND-009). Modules implement it on their own exceptions, so shared code
 * can render them without depending on any module.
 */
interface ExpectedFailure
{
    /** HTTP status: 403, 404, 409 or 422. */
    public function status(): int;

    /** Stable envelope code: `forbidden`, `not_found`, `conflict`, `validation_failed`. */
    public function errorCode(): string;

    /** Translation key under `errors.` for the human text. */
    public function messageKey(): string;

    /** @return array{reason: string, action: 'refresh'|'retry'|'review'}|null */
    public function conflict(): ?array;

    /** @return list<string> input names that failed (for `validation_failed`) */
    public function invalidFields(): array;
}
