<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\DocumentService;
use App\Modules\HumanResource\Application\EmployeeService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The employees, their papers and their offboarding. Every rule and permission lives in the application services. */
final readonly class EmployeeController
{
    public function __construct(private EmployeeService $employees, private DocumentService $documents, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:10']]);

        return Inertia::render('hr/pages/employees', ['overview' => $this->employees->overview($this->property->current(), $this->actor($request), $data['status'] ?? null)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->employees->show($this->property->current(), $this->actor($request), $id));
    }

    public function create(Request $request): JsonResponse
    {
        return $this->json($this->employees->create($this->property->current(), $this->actor($request), $this->fields($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $lock = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json($this->employees->update($this->property->current(), $this->actor($request), $id, $this->fields($request), (int) $lock['lock_version']));
    }

    public function offboard(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:14'], 'offboarded_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:200'], 'reassign_to' => ['nullable', 'string', 'size:26'], 'lock_version' => ['required', 'integer', 'min:0'],
            'items' => ['nullable', 'array', 'max:20'], 'items.*.item' => ['required', 'string', 'max:120'], 'items.*.returned' => ['required', 'boolean'], 'items.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json($this->employees->offboard($this->property->current(), $this->actor($request), $id, $data['kind'], $data['offboarded_on'], $data['reason'] ?? null, $data['items'] ?? [], $data['reassign_to'] ?? null, (int) $data['lock_version']));
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['warn_days' => ['required', 'integer', 'min:1', 'max:365'], 'required_kinds' => ['nullable', 'array'], 'required_kinds.*' => ['string', 'max:12'], 'lock_version' => ['nullable', 'integer', 'min:0']]);

        return $this->json($this->employees->saveSettings($this->property->current(), $this->actor($request), (int) $data['warn_days'], $data['required_kinds'] ?? [], isset($data['lock_version']) ? (int) $data['lock_version'] : null));
    }

    public function documents(Request $request, string $id): JsonResponse
    {
        return $this->json(['documents' => $this->documents->list($this->property->current(), $this->actor($request), $id)]);
    }

    public function addDocument(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:12'], 'title' => ['required', 'string', 'max:120'], 'issued_on' => ['nullable', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d'], 'replaces_id' => ['nullable', 'string', 'size:26'], 'file' => ['nullable', 'file', 'max:5120'],
        ]);
        $upload = $request->file('file');

        return $this->json(['documents' => $this->documents->add($this->property->current(), $this->actor($request), $id, $data['kind'], $data['title'], $data['issued_on'] ?? null, $data['valid_until'] ?? null, $data['replaces_id'] ?? null,
            $upload === null ? null : (string) $upload->get(), $upload?->getClientOriginalName())], 201);
    }

    public function download(Request $request, string $document): HttpResponse
    {
        $content = $this->documents->download($this->property->current(), $this->actor($request), $document);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'attachment; filename="paper"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string, mixed> */
    private function fields(Request $request): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:120'], 'department' => ['required', 'string', 'max:16'], 'position' => ['required', 'string', 'max:80'], 'joined_on' => ['required', 'date_format:Y-m-d'],
            'contract_type' => ['required', 'string', 'max:10'], 'contract_end_on' => ['nullable', 'date_format:Y-m-d'], 'supervisor_id' => ['nullable', 'string', 'size:26'], 'user_id' => ['nullable', 'string', 'size:26'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'string', 'max:120'],
        ]);
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
