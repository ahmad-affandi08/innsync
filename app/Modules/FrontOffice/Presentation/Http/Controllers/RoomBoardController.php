<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Board\RoomBoardService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class RoomBoardController
{
    public function __construct(private RoomBoardService $board, private PropertyContext $property) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('front-office/pages/room-board', ['board' => $this->board->board($this->property->current(), (string) $request->user()->getAuthIdentifier())]);
    }
}
