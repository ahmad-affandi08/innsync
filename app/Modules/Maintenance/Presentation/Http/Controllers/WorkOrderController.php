<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Presentation\Http\Controllers;

use App\Modules\Maintenance\Application\WorkOrderService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The work orders of maintenance. Every rule and permission lives in the application service. */
final readonly class WorkOrderController
{
    public function __construct(private WorkOrderService $orders, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('maintenance/pages/work-orders', ['overview' => $this->orders->overview($this->property->current(), $this->actor($request))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->orders->show($this->property->current(), $this->actor($request), $id));
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:500'], 'category' => ['required', 'string', 'max:12'], 'reporter_department' => ['required', 'string', 'max:14'],
            'room_id' => ['nullable', 'string', 'size:26'], 'area' => ['nullable', 'string', 'max:80'], 'priority' => ['required', 'string', 'max:8'], 'photo' => ['nullable', 'file', 'max:5120'],
        ]);
        $upload = $request->file('photo');

        return $this->json($this->orders->report($this->property->current(), $this->actor($request), $data['title'], $data['description'] ?? null, $data['category'], $data['reporter_department'], $data['room_id'] ?? null, $data['area'] ?? null, $data['priority'],
            $upload === null ? null : (string) $upload->get(), $upload?->getClientOriginalName()), 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['technician_id' => ['required', 'string', 'size:26'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->assign($this->property->current(), $this->actor($request), $id, $data['technician_id'], (int) $data['lock_version']));
    }

    public function priority(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['priority' => ['required', 'string', 'max:8'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->reprioritize($this->property->current(), $this->actor($request), $id, $data['priority'], (int) $data['lock_version']));
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->start($this->property->current(), $this->actor($request), $id, (int) $data['lock_version']));
    }

    public function hold(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:16'], 'note' => ['nullable', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->hold($this->property->current(), $this->actor($request), $id, $data['reason'], $data['note'] ?? null, (int) $data['lock_version']));
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->resume($this->property->current(), $this->actor($request), $id, (int) $data['lock_version']));
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0'], 'photo' => ['nullable', 'file', 'max:5120']]);
        $upload = $request->file('photo');

        return $this->json($this->orders->complete($this->property->current(), $this->actor($request), $id, $data['note'], $upload === null ? null : (string) $upload->get(), $upload?->getClientOriginalName(), (int) $data['lock_version']));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']));
    }

    public function block(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:14'], 'until' => ['required', 'date_format:Y-m-d'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->blockRoom($this->property->current(), $this->actor($request), $id, $data['kind'], $data['until'], (int) $data['lock_version']));
    }

    public function releaseRoom(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->orders->releaseRoom($this->property->current(), $this->actor($request), $id, (int) $data['lock_version']));
    }

    public function sla(Request $request): JsonResponse
    {
        $data = $request->validate(['urgent' => ['required', 'integer', 'min:5', 'max:43200'], 'high' => ['required', 'integer', 'min:5', 'max:43200'], 'normal' => ['required', 'integer', 'min:5', 'max:43200'], 'low' => ['required', 'integer', 'min:5', 'max:43200'], 'lock_version' => ['nullable', 'integer', 'min:0']]);

        return $this->json($this->orders->saveSla($this->property->current(), $this->actor($request), (int) $data['urgent'], (int) $data['high'], (int) $data['normal'], (int) $data['low'], isset($data['lock_version']) ? (int) $data['lock_version'] : null));
    }

    public function photo(Request $request, string $id, string $which): HttpResponse
    {
        $content = $this->orders->photo($this->property->current(), $this->actor($request), $id, $which === 'done' ? 'done' : 'report');

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="work-order"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
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
