<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\PerformanceService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** How people are doing. Every rule and permission lives in `PerformanceService`. */
final readonly class PerformanceController
{
    public function __construct(private PerformanceService $performance, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'department' => ['nullable', 'string', 'max:16']]);

        return Inertia::render('hr/pages/performance', ['overview' => $this->performance->overview($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['from'] ?? null, $data['to'] ?? null, $data['department'] ?? null)]);
    }
}
