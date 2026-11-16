<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\EmployeeCommissionService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The kinds of commission, what each person earned and the reversal of a mistake. Every rule and permission lives in `EmployeeCommissionService`. */
final readonly class EmployeeCommissionController
{
    public function __construct(private EmployeeCommissionService $commissions, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['employee' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('hr/pages/commissions', ['overview' => $this->commissions->overview($this->property->current(), $this->actor($request), $data['employee'] ?? null)]);
    }

    public function mine(Request $request): Response
    {
        return Inertia::render('hr/pages/my-commissions', ['commissions' => $this->commissions->mine($this->property->current(), $this->actor($request))]);
    }

    public function addType(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:80']]);

        return response()->json(['type' => $this->commissions->addType($this->property->current(), $this->actor($request), $data['code'], $data['name'])], 201);
    }

    public function updateType(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json(['type' => $this->commissions->updateType($this->property->current(), $this->actor($request), $id, $data['name'], (bool) $data['is_active'], (int) $data['lock_version'])]);
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'type_id' => ['required', 'string', 'size:26'], 'earned_on' => ['required', 'date_format:Y-m-d'], 'amount_minor' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:200']]);

        return response()->json(['entry' => $this->commissions->record($this->property->current(), $this->actor($request), $data['employee_id'], $data['type_id'], $data['earned_on'], (int) $data['amount_minor'], $data['note'] ?? null)], 201);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return response()->json(['entry' => $this->commissions->reverse($this->property->current(), $this->actor($request), $id, $data['reason'])], 201);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
