<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\RevenueService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The revenue of the days the night audit closed. Every rule and permission lives in the application service. */
final readonly class RevenueController
{
    public function __construct(private RevenueService $revenue, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $property = $this->property->current();
        $report = $this->revenue->report($property, $this->actor($request), $data['from'] ?? null, $data['to'] ?? null);

        return Inertia::render('finance/pages/revenue', [
            'report' => $report, 'monthly' => $this->revenue->monthly($property, $this->actor($request), isset($data['year']) ? (int) $data['year'] : (int) substr($report['to'], 0, 4)),
        ]);
    }

    public function show(Request $request, string $date): Response
    {
        return Inertia::render('finance/pages/revenue-day', ['day' => $this->revenue->show($this->property->current(), $this->actor($request), $date)]);
    }

    public function verify(Request $request, string $date): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return $this->json(['day' => $this->revenue->verify($this->property->current(), $this->actor($request), $date, $data['note'] ?? null)]);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
