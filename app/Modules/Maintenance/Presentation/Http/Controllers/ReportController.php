<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Presentation\Http\Controllers;

use App\Modules\Maintenance\Application\EscalationService;
use App\Modules\Maintenance\Application\ReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The escalations waiting for a person, and the work order reports. Every rule and permission lives in the application services. */
final readonly class ReportController
{
    public function __construct(private ReportService $reports, private EscalationService $escalations, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $data['to'] ?? date('Y-m-d');
        $from = $data['from'] ?? date('Y-m-d', strtotime($to.' -29 days'));

        return Inertia::render('maintenance/pages/reports', ['report' => $this->reports->report($this->property->current(), (string) $request->user()->getAuthIdentifier(), $from, $to)]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        $actor = (string) $request->user()->getAuthIdentifier();
        $this->escalations->acknowledge($this->property->current(), $actor, $id, $data['note'] ?? null);

        return response()->json($this->escalations->waitingFor($this->property->current(), $actor))->header('Cache-Control', 'no-store');
    }
}
