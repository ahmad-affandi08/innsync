<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\LeaveService;
use App\Modules\HumanResource\Application\LeaveTypeService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Leave: the kinds, the requests and their decisions, and the balances. Every rule and permission lives in the two services. */
final readonly class LeaveController
{
    public function __construct(private LeaveService $leave, private LeaveTypeService $types, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'status' => ['nullable', 'string', 'max:20']]);
        $property = $this->property->current();
        $overview = $this->leave->overview($property, $this->actor($request), isset($data['year']) ? (int) $data['year'] : null, $data['status'] ?? null);

        return Inertia::render('hr/pages/leave', ['overview' => $overview, 'kinds' => $overview['may']['manage'] ? $this->types->list($property, $this->actor($request)) : null]);
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'string', 'size:26'], 'leave_type_id' => ['required', 'string', 'size:26'], 'from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:200'], 'evidence' => ['nullable', 'file', 'max:3072'],
        ]);
        $file = $request->file('evidence');

        return $this->json($this->leave->request($this->property->current(), $this->actor($request), $data['employee_id'] ?? null, $data['leave_type_id'], $data['from_date'], $data['to_date'], $data['reason'], $file?->get() === null ? null : (string) $file->get(), $file?->getClientOriginalName()), 201);
    }

    public function release(Request $request, string $id): JsonResponse
    {
        return $this->json($this->leave->release($this->property->current(), $this->actor($request), $id));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->json($this->leave->cancel($this->property->current(), $this->actor($request), $id));
    }

    public function evidence(Request $request, string $id): HttpResponse
    {
        $content = $this->leave->evidence($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="evidence"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'leave_type_id' => ['required', 'string', 'size:26'], 'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'days' => ['required', 'integer', 'between:-60,60'], 'reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->leave->adjust($this->property->current(), $this->actor($request), $data['employee_id'], $data['leave_type_id'], (int) $data['year'], (int) $data['days'], $data['reason']), 201);
    }

    public function createType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'], 'name' => ['required', 'string', 'max:60'], 'deducts_balance' => ['required', 'boolean'], 'entitlement_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'eligible_after_months' => ['nullable', 'integer', 'min:0', 'max:60'],
            'evidence_after_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'paid' => ['required', 'boolean'],
        ]);

        return $this->json($this->types->create($this->property->current(), $this->actor($request), $data['code'], $data['name'], (bool) $data['deducts_balance'], (int) ($data['entitlement_days'] ?? 0), (int) ($data['eligible_after_months'] ?? 0), isset($data['evidence_after_days']) ? (int) $data['evidence_after_days'] : null, (bool) $data['paid']), 201);
    }

    public function updateType(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'], 'entitlement_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'eligible_after_months' => ['nullable', 'integer', 'min:0', 'max:60'], 'evidence_after_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'paid' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        return $this->json($this->types->update($this->property->current(), $this->actor($request), $id, $data['name'], (int) ($data['entitlement_days'] ?? 0), (int) ($data['eligible_after_months'] ?? 0), isset($data['evidence_after_days']) ? (int) $data['evidence_after_days'] : null, (bool) $data['paid'], (int) $data['lock_version']));
    }

    public function typeActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->types->setActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version']));
    }

    public function baseline(Request $request): JsonResponse
    {
        return $this->json(['types' => $this->types->baseline($this->property->current(), $this->actor($request))], 201);
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
