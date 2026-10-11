<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\BankStatementService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The bank statement against the books. Every rule and permission lives in the service. */
final readonly class BankStatementController
{
    public function __construct(private BankStatementService $bank, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:8']]);

        return Inertia::render('finance/pages/bank', ['overview' => $this->bank->overview($this->property->current(), $this->actor($request), $data['status'] ?? null)]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:2000'], 'account_label' => ['required', 'string', 'max:40'], 'dry_run' => ['required', 'boolean']]);
        $file = $request->file('file');
        $report = $this->bank->import($this->property->current(), $this->actor($request), $data['account_label'], (string) $file?->getClientOriginalName(), (string) file_get_contents((string) $file?->getRealPath()), (bool) $data['dry_run']);

        return $this->json($report);
    }

    public function match(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:20'], 'target_id' => ['required', 'string', 'size:26']]);
        $this->bank->match($this->property->current(), $this->actor($request), $id, $data['type'], $data['target_id']);

        return $this->json(['ok' => true]);
    }

    public function ignore(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);
        $this->bank->ignore($this->property->current(), $this->actor($request), $id, $data['note']);

        return $this->json(['ok' => true]);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $this->bank->reopen($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['ok' => true]);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): JsonResponse
    {
        return response()->json($body)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
