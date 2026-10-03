<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\PayComponentService;
use App\Modules\HumanResource\Application\PayrollBasisService;
use App\Modules\HumanResource\Application\PayrollSettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The payroll basis: kinds of earning, what each person earns, their tax data, and the parameters of the tax and the social security. Every rule and permission lives in the services. */
final readonly class PayrollController
{
    public function __construct(private PayrollBasisService $basis, private PayComponentService $components, private PayrollSettingsService $settings, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['employee' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('hr/pages/payroll', ['overview' => $this->basis->overview($this->property->current(), $this->actor($request), $data['employee'] ?? null)]);
    }

    public function setPay(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'component_id' => ['required', 'string', 'size:26'], 'amount_minor' => ['required', 'integer', 'min:0'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:200']]);

        return response()->json($this->basis->setPay($this->property->current(), $this->actor($request), $data['employee_id'], $data['component_id'], (int) $data['amount_minor'], $data['effective_from'], $data['reason']), 201);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'string', 'size:26'], 'ptkp_status' => ['required', 'string', 'max:3'], 'has_npwp' => ['required', 'boolean'], 'in_health' => ['required', 'boolean'], 'in_employment' => ['required', 'boolean'], 'lock_version' => ['nullable', 'integer', 'min:0']]);

        return response()->json($this->basis->saveProfile($this->property->current(), $this->actor($request), $data['employee_id'], $data['ptkp_status'], (bool) $data['has_npwp'], (bool) $data['in_health'], (bool) $data['in_employment'], isset($data['lock_version']) ? (int) $data['lock_version'] : null));
    }

    public function createComponent(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:8'], 'name' => ['required', 'string', 'max:60'], 'kind' => ['required', 'string', 'max:20'], 'taxable' => ['required', 'boolean'], 'social_base' => ['required', 'boolean']]);

        return response()->json($this->components->create($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['kind'], (bool) $data['taxable'], (bool) $data['social_base']), 201);
    }

    public function updateComponent(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'taxable' => ['required', 'boolean'], 'social_base' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->components->update($this->property->current(), $this->actor($request), $id, $data['name'], (bool) $data['taxable'], (bool) $data['social_base'], (int) $data['lock_version']));
    }

    public function componentActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->components->setActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version']));
    }

    public function baseline(Request $request): JsonResponse
    {
        return response()->json(['components' => $this->components->baseline($this->property->current(), $this->actor($request))], 201);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'health_employee_bp' => ['required', 'integer', 'min:0'], 'health_employer_bp' => ['required', 'integer', 'min:0'], 'health_cap_minor' => ['required', 'integer', 'min:0'],
            'jht_employee_bp' => ['required', 'integer', 'min:0'], 'jht_employer_bp' => ['required', 'integer', 'min:0'], 'jp_employee_bp' => ['required', 'integer', 'min:0'], 'jp_employer_bp' => ['required', 'integer', 'min:0'], 'jp_cap_minor' => ['required', 'integer', 'min:0'],
            'jkk_employer_bp' => ['required', 'integer', 'min:0'], 'jkm_employer_bp' => ['required', 'integer', 'min:0'], 'job_cost_bp' => ['required', 'integer', 'min:0'], 'job_cost_cap_year_minor' => ['required', 'integer', 'min:0'], 'no_npwp_surcharge_bp' => ['required', 'integer', 'min:0'],
            'ptkp' => ['required', 'array'], 'ptkp.*' => ['required', 'integer', 'min:0'], 'brackets' => ['required', 'array', 'max:8'], 'brackets.*.upto_minor' => ['nullable', 'integer', 'min:1'], 'brackets.*.rate_bp' => ['required', 'integer', 'min:0'],
            'overtime_divisor' => ['required', 'integer', 'min:1'], 'overtime_first_x100' => ['required', 'integer', 'min:100'], 'overtime_next_x100' => ['required', 'integer', 'min:100'], 'absence_divisor' => ['required', 'integer', 'min:1'], 'late_minute_deduction_minor' => ['required', 'integer', 'min:0'],
            'lock_version' => ['nullable', 'integer', 'min:0'],
        ]);
        $lock = isset($data['lock_version']) ? (int) $data['lock_version'] : null;
        unset($data['lock_version']);

        return response()->json($this->settings->save($this->property->current(), $this->actor($request), $data, $lock));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
