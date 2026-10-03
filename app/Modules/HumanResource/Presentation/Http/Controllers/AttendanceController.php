<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\AttendanceCorrectionService;
use App\Modules\HumanResource\Application\AttendanceService;
use App\Modules\HumanResource\Application\OvertimeService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/** Attendance: clocking in and out, the day and period views, and how attendance is taken. Every rule and permission lives in `AttendanceService`. */
final readonly class AttendanceController
{
    public function __construct(private AttendanceService $attendance, private OvertimeService $overtime, private AttendanceCorrectionService $corrections, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'department' => ['nullable', 'string', 'max:16']]);

        $property = $this->property->current();
        $overview = $this->attendance->overview($property, $this->actor($request), $data['date'] ?? null, $data['from'] ?? null, $data['to'] ?? null, $data['department'] ?? null);
        $manage = $overview['may']['manage'];

        return Inertia::render('hr/pages/attendance', [
            'overview' => $overview,
            'overtime' => $manage ? $this->overtime->overview($property, $this->actor($request)) : null,
            'corrections' => $manage ? $this->corrections->overview($property, $this->actor($request)) : null,
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        [$lat, $lng, $photo] = $this->punch($request);

        return $this->json(['me' => $this->attendance->clockIn($this->property->current(), $this->actor($request), $lat, $lng, $photo?->get() === null ? null : (string) $photo->get(), $photo?->getClientOriginalName())]);
    }

    public function clockOut(Request $request): JsonResponse
    {
        [$lat, $lng, $photo] = $this->punch($request);

        return $this->json(['me' => $this->attendance->clockOut($this->property->current(), $this->actor($request), $lat, $lng, $photo?->get() === null ? null : (string) $photo->get(), $photo?->getClientOriginalName())]);
    }

    public function manual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'size:26'], 'work_date' => ['required', 'date_format:Y-m-d'], 'in_time' => ['required', 'string', 'size:5'], 'out_time' => ['nullable', 'string', 'size:5'], 'reason' => ['required', 'string', 'max:200'],
        ]);

        return $this->json(['row' => $this->attendance->recordManually($this->property->current(), $this->actor($request), $data['employee_id'], $data['work_date'], $data['in_time'], $data['out_time'] ?? null, $data['reason'])], 201);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'radius_m' => ['required', 'integer', 'min:20', 'max:5000'], 'require_selfie' => ['required', 'boolean'],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:120'], 'early_grace_minutes' => ['required', 'integer', 'min:0', 'max:120'], 'extra_after_minutes' => ['required', 'integer', 'min:0', 'max:240'], 'lock_version' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->json($this->attendance->saveSettings($this->property->current(), $this->actor($request), isset($data['latitude']) ? (float) $data['latitude'] : null, isset($data['longitude']) ? (float) $data['longitude'] : null, (int) $data['radius_m'], (bool) $data['require_selfie'],
            (int) $data['late_grace_minutes'], (int) $data['early_grace_minutes'], (int) $data['extra_after_minutes'], isset($data['lock_version']) ? (int) $data['lock_version'] : null));
    }

    public function photo(Request $request, string $id, string $which): HttpResponse
    {
        $content = $this->attendance->download($this->property->current(), $this->actor($request), $id, $which);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="selfie"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{0: float|null, 1: float|null, 2: UploadedFile|null} */
    private function punch(Request $request): array
    {
        $data = $request->validate(['latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'photo' => ['nullable', 'file', 'max:3072']]);
        $photo = $request->file('photo');

        return [isset($data['latitude']) ? (float) $data['latitude'] : null, isset($data['longitude']) ? (float) $data['longitude'] : null, $photo];
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
