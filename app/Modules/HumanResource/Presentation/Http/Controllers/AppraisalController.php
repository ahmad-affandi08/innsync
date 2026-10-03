<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\AppraisalService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Appraisals and their forms. Every rule and permission lives in `AppraisalService`; a signature needs a recent password confirmation. */
final readonly class AppraisalController
{
    public function __construct(private AppraisalService $appraisals, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('hr/pages/appraisals', ['overview' => $this->appraisals->overview($this->property->current(), $this->actor($request))]);
    }

    public function createForm(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'criteria' => ['required', 'array', 'max:12'], 'criteria.*.label' => ['required', 'string', 'max:80'], 'criteria.*.weight' => ['required', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->appraisals->createForm($this->property->current(), $this->actor($request), $data['name'], array_map(static fn (array $c): array => ['label' => $c['label'], 'weight' => (int) $c['weight']], $data['criteria'])), 201);
    }

    public function baselineForm(Request $request): JsonResponse
    {
        return response()->json($this->appraisals->baselineForm($this->property->current(), $this->actor($request)), 201);
    }

    public function formActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->appraisals->setFormActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version']));
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'form_id' => ['required', 'string', 'size:26'], 'period_label' => ['required', 'string', 'max:30'], 'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d']]);

        return response()->json($this->appraisals->create($this->property->current(), $this->actor($request), $data['employee_id'], $data['form_id'], $data['period_label'], $data['period_start'], $data['period_end']), 201);
    }

    public function save(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['scores' => ['present', 'array'], 'scores.*' => ['integer'], 'comment' => ['nullable', 'string', 'max:1000'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->appraisals->save($this->property->current(), $this->actor($request), $id, array_map('intval', $data['scores']), $data['comment'] ?? null, (int) $data['lock_version']));
    }

    public function sign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->appraisals->signAsAppraiser($this->property->current(), $this->actor($request), $id, (int) $data['lock_version']));
    }

    public function signAsEmployee(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['agrees' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:500'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->appraisals->signAsEmployee($this->property->current(), $this->actor($request), $id, (bool) $data['agrees'], $data['comment'] ?? null, (int) $data['lock_version']));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->appraisals->cancel($this->property->current(), $this->actor($request), $id, $data['reason'], (int) $data['lock_version']));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
