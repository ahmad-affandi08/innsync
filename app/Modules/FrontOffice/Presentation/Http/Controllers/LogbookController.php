<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Routine\ShiftLogService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The handover log between shifts. Every rule lives in `ShiftLogService`. */
final readonly class LogbookController
{
    public function __construct(private ShiftLogService $log, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/logbook', ['log' => $this->log->read($this->property->current(), $this->actor($request))]);
    }

    public function write(Request $request): JsonResponse
    {
        $data = $request->validate(['shift' => ['required', 'string', 'max:9'], 'body' => ['required', 'string', 'max:2000'], 'important' => ['nullable', 'boolean']]);

        return $this->json(['entry' => $this->log->write($this->property->current(), $this->actor($request), $data['shift'], $data['body'], (bool) ($data['important'] ?? false))], 201);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['entries' => ['required', 'array', 'min:1', 'max:100'], 'entries.*' => ['required', 'string', 'size:26']]);

        return $this->json(['marked' => $this->log->markRead($this->property->current(), $this->actor($request), array_values($data['entries']))]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
