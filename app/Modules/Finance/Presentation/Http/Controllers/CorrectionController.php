<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\CorrectionService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Corrections to booked revenue days. Every rule and permission lives in the application service. */
final readonly class CorrectionController
{
    public function __construct(private CorrectionService $corrections, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10']]);

        return Inertia::render('finance/pages/corrections', ['overview' => $this->corrections->overview($this->property->current(), $this->actor($request), $data['status'] ?? null), 'status' => $data['status'] ?? '']);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('finance/pages/correction', ['correction' => $this->corrections->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:300'], 'lines' => ['required', 'array', 'min:1', 'max:20'], 'lines.*.kind' => ['required', 'string', 'in:revenue,payment'],
            'lines.*.outlet_code' => ['nullable', 'string', 'max:20'], 'lines.*.base_minor' => ['nullable', 'integer', 'min:-9000000000000', 'max:9000000000000'], 'lines.*.service_charge_minor' => ['nullable', 'integer', 'min:-9000000000000', 'max:9000000000000'],
            'lines.*.tax_minor' => ['nullable', 'integer', 'min:-9000000000000', 'max:9000000000000'], 'lines.*.method' => ['nullable', 'string', 'max:20'], 'lines.*.received_minor' => ['nullable', 'integer', 'min:-9000000000000', 'max:9000000000000'],
        ]);

        return $this->json(['correction' => $this->corrections->request($this->property->current(), $this->actor($request), $data['date'], $data['reason'], array_values($data['lines']), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'string', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['correction' => $this->corrections->decide($this->property->current(), $this->actor($request), $id, $data['decision'] === 'approve', $data['note'] ?? null, (int) $data['lock_version'])]);
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
