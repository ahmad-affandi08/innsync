<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\EmployeePortalService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The employee's own page. Everything is read for the person the account belongs to. */
final readonly class EmployeePortalController
{
    public function __construct(private EmployeePortalService $portal, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('hr/pages/me', ['portal' => $this->portal->overview($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }
}
