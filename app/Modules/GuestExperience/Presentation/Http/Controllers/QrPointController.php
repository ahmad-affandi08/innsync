<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\QrPointService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The QR codes of the rooms and tables, for the staff who make and print them. Every rule and permission lives in `QrPointService`. */
final readonly class QrPointController
{
    public function __construct(private QrPointService $points, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('guest/pages/qr-points', ['overview' => $this->points->overview($this->property->current(), $this->actor($request))]);
    }

    public function provision(Request $request): JsonResponse
    {
        return response()->json($this->points->provision($this->property->current(), $this->actor($request)), 201)->header('Cache-Control', 'no-store');
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->points->rotate($this->property->current(), $this->actor($request), $id, (int) $data['lock_version']))->header('Cache-Control', 'no-store');
    }

    public function active(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'active' => ['required', 'boolean']]);

        return response()->json($this->points->setActive($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], (bool) $data['active']))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->points->reveal($this->property->current(), $this->actor($request), $id))->header('Cache-Control', 'no-store');
    }

    public function print(Request $request): Response
    {
        return Inertia::render('guest/pages/qr-print', ['codes' => $this->points->printable($this->property->current(), $this->actor($request))]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
