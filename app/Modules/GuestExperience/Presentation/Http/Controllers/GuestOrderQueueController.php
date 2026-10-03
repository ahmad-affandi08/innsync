<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\GuestOrderQueueService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What guests ordered from their phones and the charges to a room waiting for a person. Every rule and permission lives in `GuestOrderQueueService`. */
final readonly class GuestOrderQueueController
{
    public function __construct(private GuestOrderQueueService $queue, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('guest/pages/orders-queue', ['overview' => $this->queue->overview($this->property->current(), $this->actor($request))]);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'accept' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:200']]);

        return response()->json($this->queue->decide($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], (bool) $data['accept'], $data['note'] ?? null))->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
