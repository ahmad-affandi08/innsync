<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Commissions\CommissionService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Commissions owed to agents and channels. Every rule and permission lives in the service. */
final readonly class CommissionController
{
    public function __construct(private CommissionService $commissions, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['partner' => ['nullable', 'string', 'size:26'], 'status' => ['nullable', 'string', 'max:8']]);

        return Inertia::render('front-office/pages/commissions', ['overview' => $this->commissions->overview($this->property->current(), $this->actor($request), $data['partner'] ?? null, $data['status'] ?? null)]);
    }

    public function addPartner(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:8'], 'key' => ['required', 'string', 'max:40'], 'rate_bp' => ['required', 'integer', 'min:1', 'max:5000']]);

        return $this->json(['overview' => $this->commissions->addPartner($this->property->current(), $this->actor($request), $data['kind'], $data['key'], (int) $data['rate_bp'])], 201);
    }

    public function changePartner(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['rate_bp' => ['required', 'integer', 'min:1', 'max:5000'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['overview' => $this->commissions->changePartner($this->property->current(), $this->actor($request), $id, (int) $data['rate_bp'], (bool) $data['is_active'], (int) $data['lock_version'])]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['accrual_ids' => ['nullable', 'array', 'max:500'], 'accrual_ids.*' => ['string', 'size:26'], 'method' => ['required', 'string', 'max:12'], 'reference' => ['nullable', 'string', 'max:60'], 'paid_on' => ['nullable', 'date_format:Y-m-d']]);

        return $this->json(['statement' => $this->commissions->pay($this->property->current(), $this->actor($request), $id, $data['accrual_ids'] ?? [], $data['method'], $data['reference'] ?? null, $data['paid_on'] ?? null)], 201);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $this->commissions->void($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['ok' => true]);
    }

    public function export(Request $request): HttpResponse
    {
        $data = $request->validate(['partner' => ['nullable', 'string', 'size:26'], 'status' => ['nullable', 'string', 'max:8']]);
        $file = $this->commissions->export($this->property->current(), $this->actor($request), $data['partner'] ?? null, $data['status'] ?? null);

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
