<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Presentation\Http\Controllers;

use App\Modules\Kitchen\Application\MenuReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The menu report of the kitchen. Every rule and permission lives in `MenuReportService`. */
final readonly class MenuReportController
{
    public function __construct(private MenuReportService $report, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'outlet' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('kitchen/pages/menu-report', ['report' => $this->report->report($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['from'] ?? null, $data['to'] ?? null, $data['outlet'] ?? null)]);
    }
}
