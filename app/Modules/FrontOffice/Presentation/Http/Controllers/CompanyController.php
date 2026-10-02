<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Companies\CompanyService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Companies and agents the hotel bills, and what they owe. Every rule lives in `CompanyService`. */
final readonly class CompanyController
{
    public function __construct(private CompanyService $companies, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('front-office/pages/companies', [
            'overview' => $this->companies->overview($property, $actor),
            'accounts' => $this->companies->accounts($property, $actor),
            'currency' => $this->companies->currency($property),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['company' => $this->companies->create($this->property->current(), $this->actor($request), $this->fields($request, true))], 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['company' => $this->companies->update($this->property->current(), $this->actor($request), $id, $this->fields($request, false), (bool) $data['is_active'], (int) $data['lock_version'], $data['reason'])])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function fields(Request $request, bool $withCode): array
    {
        $data = $request->validate([
            'code' => $withCode ? ['required', 'string', 'max:20'] : ['nullable'], 'name' => ['required', 'string', 'max:120'], 'kind' => ['required', 'string', 'max:7'],
            'contact_name' => ['nullable', 'string', 'max:100'], 'contact_phone' => ['nullable', 'string', 'max:30'], 'contact_email' => ['nullable', 'string', 'max:190'], 'tax_id' => ['nullable', 'string', 'max:30'],
            'billing_instruction' => ['nullable', 'string', 'max:500'], 'credit_limit_minor' => ['nullable', 'integer', 'min:0'], 'route_rooms' => ['nullable', 'boolean'], 'route_extras' => ['nullable', 'boolean'],
        ]);

        return $data;
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
