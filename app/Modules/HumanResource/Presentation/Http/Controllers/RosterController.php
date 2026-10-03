<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\RosterService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The shifts and the roster. Every rule and permission lives in `RosterService`. */
final readonly class RosterController
{
    public function __construct(private RosterService $roster, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'department' => ['nullable', 'string', 'max:16']]);

        return Inertia::render('hr/pages/roster', ['overview' => $this->roster->overview($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null, $data['department'] ?? null)]);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:100'], 'employee_ids.*' => ['string', 'size:26'], 'dates' => ['required', 'array', 'min:1', 'max:62'], 'dates.*' => ['date_format:Y-m-d'], 'pattern_id' => ['nullable', 'string', 'size:26'],
        ]);

        return $this->json($this->roster->assign($this->property->current(), $this->actor($request), $data['employee_ids'], $data['dates'], $data['pattern_id'] ?? null));
    }

    public function copy(Request $request): JsonResponse
    {
        $data = $request->validate(['from_start' => ['required', 'date_format:Y-m-d'], 'to_start' => ['required', 'date_format:Y-m-d'], 'department' => ['nullable', 'string', 'max:16']]);

        return $this->json($this->roster->copyWeek($this->property->current(), $this->actor($request), $data['from_start'], $data['to_start'], $data['department'] ?? null));
    }

    public function patterns(Request $request): JsonResponse
    {
        return $this->json(['patterns' => $this->roster->patterns($this->property->current(), $this->actor($request))]);
    }

    public function createPattern(Request $request): JsonResponse
    {
        $d = $this->pattern($request, true);

        return $this->json($this->roster->createPattern($this->property->current(), $this->actor($request), $d['code'], $d['name'], $d['off'], $d['starts_at'], $d['ends_at'], $d['starts2_at'], $d['ends2_at']), 201);
    }

    public function updatePattern(Request $request, string $id): JsonResponse
    {
        $d = $this->pattern($request, false);
        $lock = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->roster->updatePattern($this->property->current(), $this->actor($request), $id, $d['name'], $d['starts_at'], $d['ends_at'], $d['starts2_at'], $d['ends2_at'], (int) $lock['lock_version']));
    }

    public function patternActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->roster->setPatternActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version']));
    }

    public function baseline(Request $request): JsonResponse
    {
        return $this->json(['patterns' => $this->roster->baseline($this->property->current(), $this->actor($request))], 201);
    }

    public function minimums(Request $request): JsonResponse
    {
        $data = $request->validate(['department' => ['required', 'string', 'max:16'], 'minimums' => ['required', 'array'], 'minimums.*' => ['integer', 'min:0', 'max:200']]);

        return $this->json($this->roster->saveMinimums($this->property->current(), $this->actor($request), $data['department'], $data['minimums']));
    }

    /** @return array<string, mixed> */
    private function pattern(Request $request, bool $withCode): array
    {
        $data = $request->validate([
            'code' => $withCode ? ['required', 'string', 'max:8'] : ['nullable', 'string', 'max:8'], 'name' => ['required', 'string', 'max:40'], 'off' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'string', 'size:5'], 'ends_at' => ['nullable', 'string', 'size:5'], 'starts2_at' => ['nullable', 'string', 'size:5'], 'ends2_at' => ['nullable', 'string', 'size:5'],
        ]);

        return ['code' => $data['code'] ?? '', 'name' => $data['name'], 'off' => (bool) ($data['off'] ?? false), 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'starts2_at' => $data['starts2_at'] ?? null, 'ends2_at' => $data['ends2_at'] ?? null];
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
