<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The night audit screen and its action. Every rule lives in `NightAuditService`. */
final readonly class NightAuditController
{
    public function __construct(private NightAuditService $audits, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/night-audit', ['preview' => $this->audits->preview($this->property->current(), $this->actor($request))]);
    }

    public function show(Request $request, string $date): Response
    {
        return Inertia::render('front-office/pages/night-audit-report', ['audit' => $this->audits->find($this->property->current(), $this->actor($request), $date)]);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'waivers' => ['nullable', 'array', 'max:10'],
            'waivers.*.gate' => ['required', 'string', 'max:40'],
            'waivers.*.reason' => ['required', 'string', 'max:300'],
        ]);

        $audit = $this->audits->run($this->property->current(), $this->actor($request), array_values($data['waivers'] ?? []), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')));

        return response()->json(['audit' => $audit], 201)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
