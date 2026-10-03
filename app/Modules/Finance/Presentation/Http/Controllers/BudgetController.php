<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\BudgetService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Department budgets and the budget against actual report. Every rule and permission lives in the application service. */
final readonly class BudgetController
{
    public function __construct(private BudgetService $budgets, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return Inertia::render('finance/pages/budget', ['budget' => $this->budgets->overview($this->property->current(), $this->actor($request), isset($data['year']) ? (int) $data['year'] : null)]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'department' => ['required', 'string', 'max:16'], 'reason' => ['required', 'string', 'max:300'],
            'months' => ['required', 'array', 'min:1', 'max:12'], 'months.*.month' => ['required', 'integer', 'min:1', 'max:12'], 'months.*.revenue_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'months.*.cost_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'],
        ]);
        $months = array_map(static fn (array $m): array => ['month' => (int) $m['month'], 'revenue_minor' => (int) $m['revenue_minor'], 'cost_minor' => (int) $m['cost_minor']], array_values($data['months']));

        return $this->json(['budget' => $this->budgets->save($this->property->current(), $this->actor($request), (int) $data['year'], $data['department'], $months, $data['reason'])]);
    }

    public function report(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'string', 'max:7'], 'to' => ['nullable', 'string', 'max:7']]);

        return Inertia::render('finance/pages/budget-report', ['report' => $this->budgets->report($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null)]);
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
