<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Presentation\Http\Controllers;

use App\Modules\Maintenance\Application\VendorJobService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The work of outside vendors. Every rule and permission lives in the application service. */
final readonly class VendorJobController
{
    public function __construct(private VendorJobService $jobs, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:16']]);

        return Inertia::render('maintenance/pages/vendor-work', ['overview' => $this->jobs->overview($this->property->current(), $this->actor($request), $data['status'] ?? null)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->jobs->show($this->property->current(), $this->actor($request), $id));
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['work_order_id' => ['required', 'string', 'size:26'], 'scope' => ['required', 'string', 'max:300']]);

        return $this->json($this->jobs->create($this->property->current(), $this->actor($request), $data['work_order_id'], $data['scope']), 201);
    }

    public function quote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'string', 'size:26'], 'amount_minor' => ['required', 'integer', 'min:1'], 'valid_until' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->json($this->jobs->addQuote($this->property->current(), $this->actor($request), $id, $data['supplier_id'], (int) $data['amount_minor'], $data['valid_until'] ?? null, $data['note'] ?? null), 201);
    }

    public function choose(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['quote_id' => ['required', 'string', 'size:26'], 'reason' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->jobs->choose($this->property->current(), $this->actor($request), $id, $data['quote_id'], $data['reason'] ?? null, (int) $data['lock_version']));
    }

    public function release(Request $request, string $id): JsonResponse
    {
        return $this->json($this->jobs->release($this->property->current(), $this->actor($request), $id));
    }

    public function schedule(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['scheduled_on' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->jobs->schedule($this->property->current(), $this->actor($request), $id, $data['scheduled_on'], $data['note'] ?? null, (int) $data['lock_version']));
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'actual_minor' => ['required', 'integer', 'min:0'], 'invoice_ref' => ['nullable', 'string', 'max:40'], 'note' => ['required', 'string', 'max:300'], 'photo' => ['nullable', 'file', 'max:5120'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        $upload = $request->file('photo');

        return $this->json($this->jobs->complete($this->property->current(), $this->actor($request), $id, (int) $data['actual_minor'], $data['invoice_ref'] ?? null, $data['note'],
            $upload === null ? null : (string) $upload->get(), $upload?->getClientOriginalName(), (int) $data['lock_version']));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->jobs->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']));
    }

    public function proof(Request $request, string $id): HttpResponse
    {
        $content = $this->jobs->proof($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="vendor-job"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
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
