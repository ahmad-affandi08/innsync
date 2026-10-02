<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Presentation\Http\Controllers;

use App\Modules\InventoryPurchasing\Application\SupplierInvoiceService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Supplier invoices and their match to orders and receipts. Every rule and permission lives in the application service. */
final readonly class SupplierInvoiceController
{
    public function __construct(private SupplierInvoiceService $invoices, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10'], 'supplier' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('inventory-purchasing/pages/invoices', ['overview' => $this->invoices->overview($this->property->current(), $this->actor($request), $data['status'] ?? null, $data['supplier'] ?? null), 'status' => $data['status'] ?? '']);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('inventory-purchasing/pages/invoice', ['invoice' => $this->invoices->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'size:26'], 'invoice_number' => ['required', 'string', 'max:40'], 'invoice_date' => ['required', 'date_format:Y-m-d'], 'tax_number' => ['nullable', 'string', 'max:24'],
            'tax_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'total_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'note' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:60'], 'lines.*.order_line_id' => ['required', 'string', 'size:26'], 'lines.*.quantity' => ['required', 'string', 'max:14'], 'lines.*.unit_price_minor' => ['required', 'integer', 'min:0', 'max:10000000000'],
        ]);

        return $this->json(['invoice' => $this->invoices->record($this->property->current(), $this->actor($request), $data['order_id'], $data['invoice_number'], $data['invoice_date'], $data['tax_number'] ?? null, (int) $data['tax_minor'], (int) $data['total_minor'], $data['note'] ?? null, array_values($data['lines']), IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['invoice' => $this->invoices->approve($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['invoice' => $this->invoices->reject($this->property->current(), $this->actor($request), $id, $data['note'], (int) $data['lock_version'])]);
    }

    public function addDocument(Request $request, string $id): JsonResponse
    {
        $request->validate(['document' => ['required', 'file', 'max:5120']]);
        $upload = $request->file('document');

        return $this->json(['invoice' => $this->invoices->addDocument($this->property->current(), $this->actor($request), $id, (string) $upload->get(), $upload->getClientOriginalName())], 201);
    }

    public function document(Request $request, string $id, string $document): HttpResponse
    {
        $content = $this->invoices->document($this->property->current(), $this->actor($request), $id, $document);

        return response($content->contents, 200, [
            'Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="invoice"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
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
