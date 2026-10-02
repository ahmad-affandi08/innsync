<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\GoodsReceiptService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Goods receipts against purchase orders. Every rule and permission lives in the application service. */
final readonly class GoodsReceiptController
{
    public function __construct(private GoodsReceiptService $receipts, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['order' => ['nullable', 'string', 'size:26'], 'supplier' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('inventory-purchasing/pages/receipts', ['overview' => $this->receipts->overview($this->property->current(), $this->actor($request), $data['order'] ?? null, $data['supplier'] ?? null), 'order' => $data['order'] ?? '']);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('inventory-purchasing/pages/receipt', ['receipt' => $this->receipts->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'size:26'], 'location_id' => ['nullable', 'string', 'size:26'], 'delivery_note' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:60'], 'lines.*.order_line_id' => ['required', 'string', 'size:26'], 'lines.*.accepted' => ['nullable', 'string', 'max:14'], 'lines.*.rejected' => ['nullable', 'string', 'max:14'],
            'lines.*.condition' => ['nullable', 'string', 'max:16'], 'lines.*.rejection_reason' => ['nullable', 'string', 'max:16'], 'lines.*.note' => ['nullable', 'string', 'max:200'], 'lines.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->json(['receipt' => $this->receipts->receive($this->property->current(), $this->actor($request), $data['order_id'], $data['location_id'] ?? null, $data['delivery_note'] ?? null, $data['note'] ?? null, array_values($data['lines']), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function addPhoto(Request $request, string $id, string $line): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:5120']]);
        $upload = $request->file('photo');

        return $this->json(['receipt' => $this->receipts->addPhoto($this->property->current(), $this->actor($request), $id, $line, (string) $upload->get(), $upload->getClientOriginalName())], 201);
    }

    public function photo(Request $request, string $id, string $photo): HttpResponse
    {
        $content = $this->receipts->photo($this->property->current(), $this->actor($request), $id, $photo);

        return response($content->contents, 200, [
            'Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="delivery"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
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
