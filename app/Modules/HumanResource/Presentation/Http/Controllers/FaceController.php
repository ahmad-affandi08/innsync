<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\AttendanceService;
use App\Modules\HumanResource\Application\FaceService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Registering and removing the faces used for attendance, by whoever may run attendance, with the person present. */
final readonly class FaceController
{
    public function __construct(private FaceService $faces, private AttendanceService $attendance, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();

        return Inertia::render('hr/pages/face', ['overview' => $this->faces->overview($property, $this->actor($request)), 'mode' => $this->attendance->settings($property)['face_mode']]);
    }

    public function enrol(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate(['samples' => ['required', 'array', 'max:10'], 'samples.*' => ['required', 'array', 'max:128'], 'samples.*.*' => ['numeric'], 'agreed' => ['required', 'boolean']]);
        $this->faces->enrol($this->property->current(), $this->actor($request), $employee, array_values($data['samples']), (bool) $data['agreed']);

        return response()->json(['ok' => true], 201)->header('Cache-Control', 'no-store');
    }

    public function remove(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $this->faces->remove($this->property->current(), $this->actor($request), $employee, $data['reason']);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
