<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Desk\DeskTodayService;
use App\Modules\FrontOffice\Application\Spa\SpaService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The receptionist's day on one screen. */
final readonly class DeskTodayController
{
    public function __construct(private DeskTodayService $desk, private SpaService $spa, private PropertyContext $property) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $actor = (string) $request->user()->getAuthIdentifier();

        // A therapist signs in to the front office and lands on their own page, not on a day that is not theirs.
        if ($this->spa->worksSpaOnly($this->property->current(), $actor)) {
            return redirect('/front-office/spa/me');
        }

        return Inertia::render('front-office/pages/today', ['desk' => $this->desk->show($this->property->current(), $actor)]);
    }
}
