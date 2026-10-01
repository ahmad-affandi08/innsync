<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Migration;

use App\Modules\Property\Application\Migration\RoomMasterImport;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Console\Command;
use Throwable;

/** Cutover step: opens the room master from two files. `--dry-run` validates everything and changes nothing. */
final class ImportRoomMasterCommand extends Command
{
    protected $signature = 'import:room-master {property : Property id} {actor : User id of the person doing the import} {types : room_types.csv} {rooms : rooms.csv} {--dry-run : Validate only; nothing is kept} {--expect-types= : Signed-off number of room types} {--expect-rooms= : Signed-off number of rooms}';

    protected $description = 'Import room types and rooms from CSV files with per-row errors and control totals (all or nothing)';

    public function handle(RoomMasterImport $import, PropertyContext $context): int
    {
        $types = is_file((string) $this->argument('types')) ? (string) file_get_contents((string) $this->argument('types')) : null;
        $rooms = is_file((string) $this->argument('rooms')) ? (string) file_get_contents((string) $this->argument('rooms')) : null;

        if ($types === null || $rooms === null) {
            $this->error('Both files must exist and be readable.');

            return self::FAILURE;
        }

        try {
            $property = PropertyId::fromString((string) $this->argument('property'));
            $context->activate($property);
            $report = $import->run(
                $property, strtolower((string) $this->argument('actor')), $types, $rooms, (bool) $this->option('dry-run'),
                $this->option('expect-types') === null ? null : (int) $this->option('expect-types'), $this->option('expect-rooms') === null ? null : (int) $this->option('expect-rooms'),
            );
        } catch (Throwable $exception) {
            $this->error('The import could not run: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $context->clear();
        }

        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return in_array($report['status'], ['applied', 'validated'], true) ? self::SUCCESS : self::FAILURE;
    }
}
