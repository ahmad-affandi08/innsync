<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\GuestNotes\GuestNoteService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The flag and the note that follow a guest from stay to stay. */
final readonly class GuestNoteController
{
    public function __construct(private GuestNoteService $notes, private PropertyContext $property) {}

    public function save(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['flag' => ['nullable', 'string', 'max:10'], 'note' => ['nullable', 'string', 'max:500']]);
        $actor = (string) $request->user()->getAuthIdentifier();
        $this->notes->save($this->property->current(), $actor, $id, $data['flag'] ?? null, $data['note'] ?? null);

        return response()->json(['guest_note' => $this->notes->forReservation($this->property->current(), $actor, $id)])->header('Cache-Control', 'no-store');
    }
}
