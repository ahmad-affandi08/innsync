<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Reservations\ReservationImport;
use App\Shared\Application\Import\CsvText;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Brings in the bookings of a channel from its file: check first (nothing changes), then import. */
final readonly class ReservationImportController
{
    public function __construct(private ReservationImport $import, private PropertyContext $property) {}

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'], 'dry_run' => ['required', 'boolean']]);
        $report = $this->import->run($this->property->current(), strtolower((string) $request->user()->getAuthIdentifier()), CsvText::fromFile($request->file('file')?->getRealPath() ?: null), (bool) $data['dry_run']);

        return response()->json($report)->header('Cache-Control', 'no-store');
    }

    public function template(): Response
    {
        return response(ReservationImport::TEMPLATE, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="channel_bookings.csv"', 'Cache-Control' => 'no-store']);
    }
}
