<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Inventory\AvailabilityService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The availability calendar (FR-FO-002): rooms left per type and night, with selling markers of one rate plan. */
final readonly class AvailabilityController
{
    public function __construct(private AvailabilityService $availability, private PropertyContext $property) {}

    public function __invoke(Request $request): Response
    {
        $property = $this->property->current();
        $query = $request->validate(['from' => ['nullable', 'string', 'size:10'], 'days' => ['nullable', 'integer', 'min:1', 'max:366'], 'plan' => ['nullable', 'string', 'size:26']]);
        $days = (int) ($query['days'] ?? 14);

        return Inertia::render('front-office/pages/availability', [
            'calendar' => $this->availability->calendar($property, (string) $request->user()->getAuthIdentifier(), $query['from'] ?? null, $days, $query['plan'] ?? null),
            'plans' => $this->availability->plans($property, (string) $request->user()->getAuthIdentifier()),
            'selected_plan' => $query['plan'] ?? null,
        ]);
    }
}
