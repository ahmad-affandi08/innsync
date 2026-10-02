<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\ObligationService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Tax and service charge obligations by month. Every rule lives in `ObligationService`. */
final readonly class ObligationController
{
    public function __construct(private ObligationService $obligations, private ReportService $reports, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']]);

        return Inertia::render('reporting/pages/obligations', [
            'timeline' => $this->obligations->timeline($this->property->current(), $this->actor($request), (int) ($data['months'] ?? 6)),
            'context' => $this->reports->context($this->property->current(), $this->actor($request)),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['tax_report_day' => ['required', 'integer', 'min:1', 'max:28'], 'service_employee_share_bp' => ['required', 'integer', 'min:0', 'max:10000'], 'lock_version' => ['nullable', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['settings' => $this->obligations->saveSettings($this->property->current(), $this->actor($request), (int) $data['tax_report_day'], (int) $data['service_employee_share_bp'], isset($data['lock_version']) ? (int) $data['lock_version'] : null, $data['reason'])])->header('Cache-Control', 'no-store');
    }

    public function markReported(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'string', 'size:7'], 'reported_on' => ['required', 'string', 'size:10'], 'reference' => ['required', 'string', 'max:60']]);

        return response()->json(['filing' => $this->obligations->markReported($this->property->current(), $this->actor($request), $data['month'], $data['reported_on'], $data['reference'])], 201)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
