<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

/** One release-readiness observation. The message is for operators and must never contain secrets. */
final readonly class Finding
{
    public function __construct(
        public string $check,
        public Severity $severity,
        public string $message,
    ) {}

    public static function ok(string $check, string $message): self
    {
        return new self($check, Severity::Ok, $message);
    }

    public static function warning(string $check, string $message): self
    {
        return new self($check, Severity::Warning, $message);
    }

    public static function failure(string $check, string $message): self
    {
        return new self($check, Severity::Failure, $message);
    }
}
