<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\StockCountService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Stock counts (opname). Every rule and permission lives in the application service. */
final readonly class StockCountController
{
    public function __construct(private StockCountService $counts, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10'], 'location_kind' => ['nullable', 'string', 'max:12']]);

        return Inertia::render('inventory-purchasing/pages/counts', [
            'overview' => $this->counts->overview($this->property->current(), $this->actor($request), $data['status'] ?? null, $data['location_kind'] ?? null),
            'status' => $data['status'] ?? '',
            'locationKind' => $data['location_kind'] ?? '',
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('inventory-purchasing/pages/count', [
            'count' => $this->counts->show($this->property->current(), $this->actor($request), $id),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'string', 'size:26'], 'kind' => ['required', 'string', 'max:10'], 'category_id' => ['nullable', 'string', 'size:26'],
            'scheduled_for' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['count' => $this->counts->start($this->property->current(), $this->actor($request), $data['location_id'], $data['kind'], $data['category_id'] ?? null, $data['scheduled_for'] ?? null, $data['note'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'], 'lines' => ['required', 'array', 'min:1', 'max:'.StockCountService::MAX_LINES],
            'lines.*.line_id' => ['required', 'string', 'size:26'], 'lines.*.unit' => ['nullable', 'string', 'max:8'], 'lines.*.quantity' => ['nullable', 'string', 'max:14'],
            'lines.*.reason_code' => ['nullable', 'string', 'max:16'], 'lines.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['count' => $this->counts->saveCounts($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], array_values($data['lines']))]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['count' => $this->counts->submit($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'])]);
    }

    public function sendBack(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['count' => $this->counts->sendBack($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['count' => $this->counts->approve($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['note'] ?? null)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['count' => $this->counts->cancel($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
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
