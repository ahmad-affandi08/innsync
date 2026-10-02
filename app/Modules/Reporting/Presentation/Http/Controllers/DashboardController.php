<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\DashboardPreferenceService;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The dashboard screen; the page refreshes itself with a partial reload. Every rule and permission lives in `DashboardService`. */
final readonly class DashboardController
{
    public function __construct(private DashboardService $dashboard, private DashboardPreferenceService $preferences, private ReportService $reports, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('reporting/pages/dashboard', [
            'snapshot' => $this->snapshot($request),
            'currency' => $this->reports->context($this->property->current(), $this->actor($request))['currency'],
            'preferences' => $this->preferences->get($this->property->current(), $this->actor($request)),
            'tv' => $request->boolean('tv'),
        ]);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $data = $request->validate(['order' => ['required', 'array', 'max:10'], 'order.*' => ['string', 'max:20'], 'hidden' => ['present', 'array', 'max:10'], 'hidden.*' => ['string', 'max:20']]);

        return response()->json(['preferences' => $this->preferences->save($this->property->current(), $this->actor($request), array_values($data['order']), array_values($data['hidden']))])->header('Cache-Control', 'no-store');
    }

    public function resetPreferences(Request $request): JsonResponse
    {
        return response()->json(['preferences' => $this->preferences->reset($this->property->current(), $this->actor($request))])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function snapshot(Request $request): array
    {
        $input = $request->validate(['preset' => ['nullable', 'string', 'max:10'], 'from' => ['nullable', 'string', 'size:10'], 'to' => ['nullable', 'string', 'size:10'], 'tv' => ['nullable', 'boolean']]);

        return $this->dashboard->snapshot($this->property->current(), $this->actor($request), $input['preset'] ?? null, $input['from'] ?? null, $input['to'] ?? null);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
