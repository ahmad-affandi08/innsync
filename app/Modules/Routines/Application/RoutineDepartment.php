<?php

declare(strict_types=1);

namespace App\Modules\Routines\Application;

use App\Shared\Application\Errors\Refusal;

/** The departments that keep routine checklists and temperature logs, with the privileges and the events of each. */
final readonly class RoutineDepartment
{
    private const ALL = [
        'kitchen' => ['manage' => 'kitchen.sop.manage', 'perform' => 'kitchen.sop.perform', 'view' => 'kitchen.sop.view', 'temperature' => 'kitchen.temperature.record'],
        'fnb' => ['manage' => 'fnb.sop.manage', 'perform' => 'fnb.sop.perform', 'view' => 'fnb.sop.view', 'temperature' => 'fnb.temperature.record'],
    ];

    /** @param array{manage: string, perform: string, view: string, temperature: string} $permissions */
    private function __construct(public string $code, public array $permissions) {}

    public static function of(string $code): self
    {
        return isset(self::ALL[$code]) ? new self($code, self::ALL[$code]) : throw Refusal::notFound('Department not found.');
    }

    public function event(string $name): string
    {
        return "{$this->code}.{$name}";
    }
}
