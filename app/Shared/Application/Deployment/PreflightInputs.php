<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

/** Facts about the target environment, gathered by infrastructure and judged by `PreflightEvaluator`. */
final readonly class PreflightInputs
{
    /**
     * @param  list<string>  $extensions  loaded PHP extensions (any case)
     * @param  array<string, bool>  $writable  directory => is writable
     * @param  list<string>  $publicSensitiveFiles  files in the web root that must never be public
     */
    public function __construct(
        public string $phpVersion,
        public array $extensions,
        public string $appEnv,
        public bool $appDebug,
        public bool $appKeySet,
        public string $appUrl,
        public ?bool $sessionSecureCookie,
        public bool $sessionEncrypted,
        public string $queueConnection,
        public string $outboxQueueConnection,
        public bool $inertiaSsrEnabled,
        public array $writable,
        public array $publicSensitiveFiles,
        public ?string $databaseVersion,
        public ?string $databaseError,
        public ?int $pendingMigrations,
        public bool $healthTokenSet,
        public bool $idempotencyKeySet,
        public bool $backupConfigured,
    ) {}
}
