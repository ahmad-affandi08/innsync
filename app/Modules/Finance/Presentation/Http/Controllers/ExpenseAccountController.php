<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\ExpenseAccountService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Expense accounts. Every rule and permission lives in the application service. */
final readonly class ExpenseAccountController
{
    public function __construct(private ExpenseAccountService $accounts, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/pages/accounts', ['overview' => $this->accounts->overview($this->property->current(), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:80'], 'department' => ['required', 'string', 'max:16'], 'category' => ['required', 'string', 'max:16']]);

        return $this->json(['account' => $this->accounts->create($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['department'], $data['category'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'department' => ['required', 'string', 'max:16'], 'category' => ['required', 'string', 'max:16'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['account' => $this->accounts->update($this->property->current(), $this->actor($request), $id, $data['name'], $data['department'], $data['category'], (bool) $data['active'], (int) $data['lock_version'])]);
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
