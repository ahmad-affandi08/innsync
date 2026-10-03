<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\ShiftSwapService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Exchanging shifts between two people. Every rule and permission lives in `ShiftSwapService`. */
final readonly class ShiftSwapController
{
    public function __construct(private ShiftSwapService $swaps, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('hr/pages/swaps', ['overview' => $this->swaps->overview($this->property->current(), $this->actor($request))]);
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['partner_id' => ['required', 'string', 'size:26'], 'work_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:200']]);

        return response()->json($this->swaps->request($this->property->current(), $this->actor($request), $data['partner_id'], $data['work_date'], $data['reason']), 201);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return response()->json($this->swaps->respond($this->property->current(), $this->actor($request), $id, true));
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        return response()->json($this->swaps->respond($this->property->current(), $this->actor($request), $id, false));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        return response()->json($this->swaps->decide($this->property->current(), $this->actor($request), $id, true, $data['note'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        return response()->json($this->swaps->decide($this->property->current(), $this->actor($request), $id, false, $data['note']));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return response()->json($this->swaps->cancel($this->property->current(), $this->actor($request), $id));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
