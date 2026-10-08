<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\TapeChart\TapeChartService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The room calendar: rooms against nights, with who is where. */
final readonly class TapeChartController
{
    public function __construct(private TapeChartService $chart, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'string', 'size:10'], 'days' => ['nullable', 'integer', 'min:1', 'max:60']]);

        return Inertia::render('front-office/pages/tape-chart', [
            'chart' => $this->chart->show($this->property->current(), (string) $request->user()->getAuthIdentifier(), $data['from'] ?? null, (int) ($data['days'] ?? 14)),
        ]);
    }
}
