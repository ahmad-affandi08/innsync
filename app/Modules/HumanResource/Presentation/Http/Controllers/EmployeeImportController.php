<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\EmployeeImport;
use App\Shared\Application\Import\CsvText;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Opens the staff list from a CSV file on screen: check first (nothing changes), then import. */
final readonly class EmployeeImportController
{
    public function __construct(private EmployeeImport $import, private PropertyContext $property) {}

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'], 'dry_run' => ['required', 'boolean']]);
        $report = $this->import->run($this->property->current(), strtolower((string) $request->user()->getAuthIdentifier()), CsvText::fromFile($request->file('file')?->getRealPath() ?: null), (bool) $data['dry_run']);

        return response()->json($report)->header('Cache-Control', 'no-store');
    }

    public function template(): Response
    {
        return response(EmployeeImport::TEMPLATE, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="employees.csv"', 'Cache-Control' => 'no-store']);
    }
}
