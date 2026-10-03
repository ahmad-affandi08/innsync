<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\GuestSurveyReport;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The answers of the guest survey, for the staff. Every rule and permission lives in `GuestSurveyReport`. */
final readonly class GuestSurveyController
{
    public function __construct(private GuestSurveyReport $report, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('guest/pages/surveys', ['overview' => $this->report->overview($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }
}
