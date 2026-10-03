<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\FinanceExportService;
use App\Modules\Finance\Application\ManagementReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The management P&L, the cash flow summary and the exports of finance data. Every rule and permission lives in the application services. */
final readonly class ManagementReportController
{
    public function __construct(private ManagementReportService $reports, private FinanceExportService $exports, private PropertyContext $property) {}

    public function pnl(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('finance/pages/pnl', ['report' => $this->reports->pnl($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function mapOutlet(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_code' => ['required', 'string', 'max:20'], 'department' => ['required', 'string', 'max:16']]);

        return $this->json(['mapping' => $this->reports->mapOutlet($this->property->current(), $this->actor($request), $data['outlet_code'], $data['department'])]);
    }

    public function cashFlow(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return Inertia::render('finance/pages/cashflow', ['report' => $this->reports->cashFlow($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null)]);
    }

    public function setOpening(Request $request): JsonResponse
    {
        $data = $request->validate(['cash_opening_minor' => ['required', 'integer', 'min:-9000000000000', 'max:9000000000000'], 'bank_opening_minor' => ['required', 'integer', 'min:-9000000000000', 'max:9000000000000'], 'opening_date' => ['required', 'date_format:Y-m-d'], 'lock_version' => ['nullable', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return $this->json(['balances' => $this->reports->setOpening($this->property->current(), $this->actor($request), (int) $data['cash_opening_minor'], (int) $data['bank_opening_minor'], $data['opening_date'], isset($data['lock_version']) ? (int) $data['lock_version'] : null, $data['reason'])]);
    }

    public function exportPage(Request $request): Response
    {
        return Inertia::render('finance/pages/export', ['overview' => $this->exports->overview($this->property->current(), $this->actor($request))]);
    }

    public function export(Request $request, string $dataset): HttpResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $file = $this->exports->export($this->property->current(), $this->actor($request), $dataset, $data['from'] ?? null, $data['to'] ?? null);

        return response($file['contents'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
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
