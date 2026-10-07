<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Migration\RoomMasterImport;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the room master from two CSV files on screen (the same service as `import:room-master`): check first (nothing changes), then apply.
 * A refused file is a normal answer (status `rejected` with every bad row), not an error, so the screen can list the rows to fix.
 */
final readonly class RoomImportController
{
    public function __construct(private RoomMasterImport $import, private PropertyContext $property) {}

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types' => ['required', 'file', 'max:1024', 'extensions:csv,txt'],
            'rooms' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            'dry_run' => ['required', 'boolean'],
            'expect_types' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'expect_rooms' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        $report = $this->import->run(
            $this->property->current(),
            strtolower((string) $request->user()->getAuthIdentifier()),
            $this->text($request->file('types')?->getRealPath()),
            $this->text($request->file('rooms')?->getRealPath()),
            (bool) $data['dry_run'],
            isset($data['expect_types']) ? (int) $data['expect_types'] : null,
            isset($data['expect_rooms']) ? (int) $data['expect_rooms'] : null,
        );

        return response()->json($report)->header('Cache-Control', 'no-store');
    }

    /** The two example files, so a hotel can fill in its own. */
    public function template(string $kind): Response
    {
        abort_unless(in_array($kind, ['types', 'rooms'], true), 404);
        $csv = $kind === 'types'
            ? "code,name,max_adults,max_children,sort_order\nSTD,Standard,2,1,10\nDLX,Deluxe,2,2,20\n"
            : "number,type_code,floor\n101,STD,1\n102,STD,1\n201,DLX,2\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.($kind === 'types' ? 'room_types.csv' : 'rooms.csv').'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function text(?string $path): string
    {
        $text = $path === null || $path === '' ? '' : (string) file_get_contents($path);

        // A file saved by a spreadsheet often starts with a byte order mark; the header check would otherwise fail on it.
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}
