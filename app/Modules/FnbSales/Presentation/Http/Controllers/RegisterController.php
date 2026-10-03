<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Presentation\Http\Controllers;

use App\Modules\FnbSales\Application\RegisterService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The register that keeps selling when the network is down. Every rule lives in `RegisterService` and `OfflineSaleHandler`. */
final readonly class RegisterController
{
    public function __construct(private RegisterService $register, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['outlet' => ['nullable', 'string', 'size:26']]);

        return Inertia::render('fnb-sales/pages/register', ['register' => $this->register->view($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['outlet'] ?? null)]);
    }
}
