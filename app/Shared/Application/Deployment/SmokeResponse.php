<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

/** What a released site answered for one path. `status` is 0 when the request did not complete. */
final readonly class SmokeResponse
{
    /** @param array<string, list<string>> $headers lower-cased header name => values */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
        public ?string $error = null,
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : implode(', ', $values);
    }

    /** @return list<string> */
    public function setCookies(): array
    {
        return $this->headers['set-cookie'] ?? [];
    }
}
