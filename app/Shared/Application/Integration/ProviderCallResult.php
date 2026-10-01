<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Application\Observability\SensitiveDataGuard;

final readonly class ProviderCallResult
{
    /** @param array<string, mixed> $data provider answer reduced to what the module needs; never card data or secrets */
    private function __construct(
        public CallOutcome $outcome,
        public string $code,
        public array $data,
    ) {
        SensitiveDataGuard::assertSafe($data);
    }

    /** @param array<string, mixed> $data */
    public static function succeeded(array $data = []): self
    {
        return new self(CallOutcome::Succeeded, 'ok', $data);
    }

    public static function rejected(string $code): self
    {
        return new self(CallOutcome::Rejected, $code, []);
    }

    public static function retryable(string $code): self
    {
        return new self(CallOutcome::Retryable, $code, []);
    }

    public static function unknown(string $code): self
    {
        return new self(CallOutcome::Unknown, $code, []);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === CallOutcome::Succeeded;
    }
}
