<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Setup\ModuleSettings;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives a property the data most hotels in Indonesia start with, so nobody types it in again: expense accounts, shifts, kinds of leave, kinds of pay and the appraisal form.
 * Each part is written only where the property has none yet (the part is skipped when anything is there), and only for a department the property uses. The administrator is the
 * actor, so the audit trail names a person who holds the permission. Rates, taxes, rooms and hotel policy are never guessed here.
 */
final class InstallStarterDataCommand extends Command
{
    protected $signature = 'innsync:install-starter-data {--property= : Property id; every property when omitted}';

    protected $description = 'Write the usual expense accounts, shifts, kinds of leave and pay, and the appraisal form where a property has none; what exists is not touched';

    public function handle(ModuleSettings $modules, PropertyContext $context): int
    {
        $only = $this->option('property');
        $ids = DB::table('properties')->when(is_string($only) && $only !== '', static fn ($q) => $q->where('id', $only))->pluck('id');

        foreach ($ids as $id) {
            $property = PropertyId::fromString((string) $id);
            $admin = $this->administrator((string) $id);

            if ($admin === null) {
                $this->warn("{$id}: no active administrator, nothing written.");

                continue;
            }

            $off = $modules->disabled($property);
            $parts = [];

            // Resolved here, not in the constructor: every artisan command is built whenever any command runs, and these services need the application key and the database.
            foreach ($this->laravel->tagged('starter-data') as $source) {
                array_push($parts, ...$source->parts());
            }

            $context->run($property, function () use ($parts, $off, $property, $admin, $id): void {
                foreach ($parts as ['module' => $module, 'label' => $label, 'install' => $write]) {
                    if (in_array($module, $off, true)) {
                        $this->line("{$id}: {$label}: department not used, skipped");

                        continue;
                    }

                    try {
                        $this->info(sprintf('%s: %s: %d written', $id, $label, $write($property, $admin)));
                    } catch (Refusal $e) {
                        $this->line("{$id}: {$label}: skipped ({$e->getMessage()})");
                    }
                }
            });
        }

        return self::SUCCESS;
    }

    /** The oldest active person holding an active Administrator role of the property, as the starting approval policies do. */
    private function administrator(string $propertyId): ?string
    {
        $id = DB::table('user_role_assignments as a')
            ->join('roles as r', static function ($join): void {
                $join->on('r.id', '=', 'a.role_id')->on('r.property_id', '=', 'a.property_id');
            })
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.property_id', $propertyId)->where('a.is_active', true)->where('r.is_active', true)->where('r.name', 'Administrator')->where('u.is_active', true)
            ->orderBy('u.created_at')->orderBy('u.id')->value('u.id');

        return $id === null ? null : (string) $id;
    }
}
