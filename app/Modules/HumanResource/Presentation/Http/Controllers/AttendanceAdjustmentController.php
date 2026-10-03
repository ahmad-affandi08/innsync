<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\AttendanceCorrectionService;
use App\Modules\HumanResource\Application\OvertimeService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Overtime asked for beforehand and corrections of attendance. Every rule and permission lives in the two services. */
final readonly class AttendanceAdjustmentController
{
    public function __construct(private OvertimeService $overtime, private AttendanceCorrectionService $corrections, private PropertyContext $property) {}

    public function requestOvertime(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'work_date' => ['required', 'date_format:Y-m-d'], 'minutes' => ['required', 'integer', 'min:15', 'max:480'], 'reason' => ['required', 'string', 'max:200']]);

        return $this->json($this->overtime->request($this->property->current(), $this->actor($request), $data['employee_id'], $data['work_date'], (int) $data['minutes'], $data['reason']), 201);
    }

    public function releaseOvertime(Request $request, string $id): JsonResponse
    {
        return $this->json($this->overtime->release($this->property->current(), $this->actor($request), $id));
    }

    public function cancelOvertime(Request $request, string $id): JsonResponse
    {
        return $this->json($this->overtime->cancel($this->property->current(), $this->actor($request), $id));
    }

    public function requestCorrection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'size:26'], 'work_date' => ['required', 'date_format:Y-m-d'], 'in_time' => ['required', 'string', 'size:5'], 'out_time' => ['nullable', 'string', 'size:5'], 'reason' => ['required', 'string', 'max:200'],
        ]);

        return $this->json($this->corrections->request($this->property->current(), $this->actor($request), $data['employee_id'], $data['work_date'], $data['in_time'], $data['out_time'] ?? null, $data['reason']), 201);
    }

    public function applyCorrection(Request $request, string $id): JsonResponse
    {
        return $this->json($this->corrections->apply($this->property->current(), $this->actor($request), $id));
    }

    public function cancelCorrection(Request $request, string $id): JsonResponse
    {
        return $this->json($this->corrections->cancel($this->property->current(), $this->actor($request), $id));
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
