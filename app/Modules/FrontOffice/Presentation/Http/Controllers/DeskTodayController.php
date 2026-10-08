<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Desk\DeskTodayService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The receptionist's day on one screen. */
final readonly class DeskTodayController
{
    public function __construct(private DeskTodayService $desk, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/today', ['desk' => $this->desk->show($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }
}
