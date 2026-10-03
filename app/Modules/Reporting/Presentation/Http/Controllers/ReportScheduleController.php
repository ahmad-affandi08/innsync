<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\ReportScheduleService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The scheduled reports. Every rule and permission lives in `ReportScheduleService`. */
final readonly class ReportScheduleController
{
    public function __construct(private ReportScheduleService $schedules, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('reporting/pages/schedules', ['overview' => $this->schedules->overview($this->property->current(), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        [$in, $recipients] = $this->input($request);

        return response()->json($this->schedules->create($this->property->current(), $this->actor($request), $in, $recipients), 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        [$in, $recipients] = $this->input($request, true);

        return response()->json($this->schedules->update($this->property->current(), $this->actor($request), $id, (int) $request->input('lock_version'), $in, $recipients))->header('Cache-Control', 'no-store');
    }

    public function active(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'active' => ['required', 'boolean']]);

        return response()->json($this->schedules->setActive($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], (bool) $data['active']))->header('Cache-Control', 'no-store');
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function input(Request $request, bool $versioned = false): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'report' => ['required', 'string', 'max:20'], 'params' => ['nullable', 'array'], 'params.preset' => ['nullable', 'string', 'max:20'], 'params.by' => ['nullable', 'string', 'max:8'], 'params.kind' => ['nullable', 'string', 'max:8'],
            'cadence' => ['required', 'string', 'max:8'], 'weekday' => ['nullable', 'integer', 'min:1', 'max:7'], 'month_day' => ['nullable', 'integer', 'min:1', 'max:28'], 'at_time' => ['required', 'date_format:H:i'], 'notify_email' => ['required', 'boolean'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'], 'recipients.*' => ['string', 'size:26'], ...($versioned ? ['lock_version' => ['required', 'integer', 'min:0']] : []),
        ]);

        return [$data, array_values($data['recipients'])];
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
