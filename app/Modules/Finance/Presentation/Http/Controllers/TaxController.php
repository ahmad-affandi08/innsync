<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\TaxService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The regional tax and the service charge. Every rule and permission lives in `TaxService`. */
final readonly class TaxController
{
    public function __construct(private TaxService $tax, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/pages/tax', ['overview' => $this->tax->overview($this->property->current(), $this->actor($request))]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['report_day' => ['required', 'integer', 'min:1'], 'lock_version' => ['nullable', 'integer', 'min:0']]);

        return response()->json($this->tax->saveSettings($this->property->current(), $this->actor($request), (int) $data['report_day'], isset($data['lock_version']) ? (int) $data['lock_version'] : null));
    }

    public function report(Request $request, string $period): JsonResponse
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:80']]);

        return response()->json($this->tax->report($this->property->current(), $this->actor($request), $period, $data['reference'] ?? null), 201);
    }

    public function deposit(Request $request, string $period): JsonResponse
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:0'], 'deposited_on' => ['required', 'date_format:Y-m-d'], 'reference' => ['required', 'string', 'max:80'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->tax->deposit($this->property->current(), $this->actor($request), $period, (int) $data['amount_minor'], $data['deposited_on'], $data['reference'], (int) $data['lock_version']));
    }

    public function recap(Request $request, string $period): HttpResponse
    {
        $file = $this->tax->recap($this->property->current(), $this->actor($request), $period);

        return response($file['contents'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
