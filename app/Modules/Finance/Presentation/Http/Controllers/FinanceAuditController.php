<?php

declare(strict_types=1);

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Modules\Finance\Application\FinanceAuditService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The audit trail of financial changes. Every rule and permission lives in the application service. */
final readonly class FinanceAuditController
{
    public function __construct(private FinanceAuditService $audit, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'group' => ['nullable', 'string', 'max:16'], 'user' => ['nullable', 'string', 'size:26'], 'action' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return Inertia::render('finance/pages/audit', ['trail' => $this->audit->trail($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['from'] ?? null, $data['to'] ?? null, $data['group'] ?? null, $data['user'] ?? null, $data['action'] ?? null, (int) ($data['page'] ?? 1))]);
    }
}
