<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Offline\SyncExceptionService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The offline entries the server could not apply, for a manager to reconcile. Every rule and permission lives in `SyncExceptionService`. */
final readonly class SyncExceptionController
{
    public function __construct(private SyncExceptionService $exceptions, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:8']]);
        $status = $data['status'] ?? 'open';

        return Inertia::render('foundation/pages/sync-exceptions', ['overview' => $this->exceptions->overview($this->property->current(), $this->actor($request), $status), 'status' => $status]);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'note' => ['required', 'string', 'max:600']]);
        $this->exceptions->resolve($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $data['note']);

        return response()->json(['resolved' => true])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
