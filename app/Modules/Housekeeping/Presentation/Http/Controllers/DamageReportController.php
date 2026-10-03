<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Presentation\Http\Controllers;

use App\Modules\Housekeeping\Application\RoomDamageReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Faults found by housekeeping. Every rule and permission lives in `RoomDamageReportService`. */
final readonly class DamageReportController
{
    public function __construct(private RoomDamageReportService $reports, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('housekeeping/pages/damage-reports', ['overview' => $this->reports->overview($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['nullable', 'string', 'size:26'], 'area' => ['nullable', 'string', 'max:80'], 'category' => ['required', 'string', 'max:12'], 'title' => ['required', 'string', 'max:80'], 'detail' => ['nullable', 'string', 'max:500'],
            'urgent' => ['nullable', 'boolean'], 'photo' => ['nullable', 'file', 'max:5120'],
        ]);
        $upload = $request->file('photo');
        $made = $this->reports->report($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['room_id'] ?? null, $data['area'] ?? null, $data['category'], $data['title'], $data['detail'] ?? null, (bool) ($data['urgent'] ?? false), $upload === null ? null : (string) $upload->get(), $upload?->getClientOriginalName());

        return response()->json(['report' => $made], 201)->header('Cache-Control', 'no-store');
    }
}
