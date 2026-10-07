<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\DashboardPreferenceService;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\DrillDownService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The dashboard screen; the page refreshes itself with a partial reload. Every rule and permission lives in `DashboardService`. */
final readonly class DashboardController
{
    public function __construct(private DashboardService $dashboard, private DashboardPreferenceService $preferences, private DrillDownService $drills, private ReportService $reports, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('reporting/pages/dashboard', [
            'snapshot' => $this->snapshot($request),
            'currency' => $this->reports->context($this->property->current(), $this->actor($request))['currency'],
            'preferences' => $this->preferences->get($this->property->current(), $this->actor($request)),
            'tv' => $request->boolean('tv'),
        ]);
    }

    /** The few numbers the home page shows for today. A person without dashboard access gets the service's refusal, and the page simply shows none. */
    public function today(Request $request): JsonResponse
    {
        $snapshot = $this->dashboard->snapshot($this->property->current(), $this->actor($request), 'today', null, null);
        $values = [];

        foreach ($snapshot['cards'] as $card) {
            $values[(string) $card['key']] = $card['values'];
        }

        return response()->json([
            'business_date' => $snapshot['business_date'],
            'occupancy' => $values['occupancy'] ?? null,
            'movements' => $values['movements'] ?? null,
            'alerts' => count($snapshot['alerts']),
        ])->header('Cache-Control', 'no-store');
    }

    /** The rows behind the figures of a card (FR-DSH-016). The page is one card, one figure at a time, for the period the card was opened with. */
    public function drill(Request $request, string $card): Response
    {
        $input = $request->validate(['metric' => ['nullable', 'string', 'max:30'], 'preset' => ['nullable', 'string', 'max:10'], 'from' => ['nullable', 'string', 'size:10'], 'to' => ['nullable', 'string', 'size:10']]);

        return Inertia::render('reporting/pages/drill', [
            'drill' => $this->drills->drill($this->property->current(), $this->actor($request), $card, $input['metric'] ?? null, $input['preset'] ?? null, $input['from'] ?? null, $input['to'] ?? null),
            'currency' => $this->reports->context($this->property->current(), $this->actor($request))['currency'],
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
