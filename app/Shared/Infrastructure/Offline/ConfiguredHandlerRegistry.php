<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Offline\OfflineHandlerRegistry;
use App\Shared\Application\Offline\OfflineOperationHandler;
use Illuminate\Contracts\Container\Container;
use LogicException;

/** Handlers are registered by their owning module in config/offline.php. */
final class ConfiguredHandlerRegistry implements OfflineHandlerRegistry
{
    /** @var array<string, class-string<OfflineOperationHandler>>|null */
    private ?array $classes = null;

    /** @param list<class-string<OfflineOperationHandler>> $handlers */
    public function __construct(private readonly Container $container, private readonly array $handlers) {}

    public function find(string $type): ?OfflineOperationHandler
    {
        $class = $this->classes()[$type] ?? null;

        return $class === null ? null : $this->container->make($class);
    }

    /** @return array<string, class-string<OfflineOperationHandler>> */
    private function classes(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        $map = [];

        foreach ($this->handlers as $class) {
            $type = $this->container->make($class)->type();

            if (isset($map[$type])) {
                throw new LogicException("Two offline handlers claim the operation type {$type}.");
            }

            $map[$type] = $class;
        }

        return $this->classes = $map;
    }
}
