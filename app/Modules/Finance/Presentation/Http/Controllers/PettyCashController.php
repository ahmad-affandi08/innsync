<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\PettyCashService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Petty cash funds, vouchers, settlements. Every rule and permission lives in the application service. */
final readonly class PettyCashController
{
    public function __construct(private PettyCashService $petty, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('finance/pages/petty-funds', ['overview' => $this->petty->overview($this->property->current(), $this->actor($request))]);
    }

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('finance/pages/petty-fund', ['fund' => $this->petty->show($this->property->current(), $this->actor($request), $id)]);
    }

    public function showSettlement(Request $request, string $id): Response
    {
        return Inertia::render('finance/pages/petty-settlement', ['settlement' => $this->petty->showSettlement($this->property->current(), $this->actor($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:80'], 'custodian_id' => ['required', 'string', 'size:26'], 'imprest_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'max_voucher_minor' => ['nullable', 'integer', 'min:1', 'max:9000000000000']]);

        return $this->json(['fund' => $this->petty->createFund($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['custodian_id'], (int) $data['imprest_minor'], isset($data['max_voucher_minor']) ? (int) $data['max_voucher_minor'] : null)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'custodian_id' => ['required', 'string', 'size:26'], 'max_voucher_minor' => ['nullable', 'integer', 'min:1', 'max:9000000000000'], 'active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['fund' => $this->petty->updateFund($this->property->current(), $this->actor($request), $id, $data['name'], $data['custodian_id'], isset($data['max_voucher_minor']) ? (int) $data['max_voucher_minor'] : null, (bool) $data['active'], (int) $data['lock_version'])]);
    }

    public function record(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'voucher_date' => ['nullable', 'date_format:Y-m-d'], 'payee' => ['required', 'string', 'max:120'], 'description' => ['required', 'string', 'max:200'], 'expense_account_id' => ['required', 'string', 'size:26'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:9000000000000'], 'receipt_ref' => ['nullable', 'string', 'max:40'], 'no_receipt_reason' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->json(['fund' => $this->petty->record($this->property->current(), $this->actor($request), $id, $data['voucher_date'] ?? null, $data['payee'], $data['description'], $data['expense_account_id'], (int) $data['amount_minor'], $data['receipt_ref'] ?? null, $data['no_receipt_reason'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function addProof(Request $request, string $id): JsonResponse
    {
        $request->validate(['proof' => ['required', 'file', 'max:5120']]);
        $upload = $request->file('proof');

        return $this->json(['fund' => $this->petty->addProof($this->property->current(), $this->actor($request), $id, (string) $upload->get(), $upload->getClientOriginalName())], 201);
    }

    public function proof(Request $request, string $id, string $proof): HttpResponse
    {
        $content = $this->petty->proof($this->property->current(), $this->actor($request), $id, $proof);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="proof"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return $this->json(['fund' => $this->petty->void($this->property->current(), $this->actor($request), $id, $data['reason'])]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['counted_minor' => ['required', 'integer', 'min:0', 'max:9000000000000'], 'variance_reason' => ['nullable', 'string', 'max:300']]);

        return $this->json(['settlement' => $this->petty->submit($this->property->current(), $this->actor($request), $id, (int) $data['counted_minor'], $data['variance_reason'] ?? null, IdempotencyKey::fromString((string) $request->header('Idempotency-Key')))], 201);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'string', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['settlement' => $this->petty->decide($this->property->current(), $this->actor($request), $id, $data['decision'] === 'approve', $data['note'] ?? null, (int) $data['lock_version'])]);
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
