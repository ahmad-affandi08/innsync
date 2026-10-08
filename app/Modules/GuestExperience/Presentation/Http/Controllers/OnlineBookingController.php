<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\OnlineBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The hotel's own booking page: anyone may look and send a request; staff confirm it. */
final readonly class OnlineBookingController
{
    public function __construct(private OnlineBookingService $booking) {}

    public function show(Request $request): Response
    {
        return Inertia::render('guest/pages/book', ['property_id' => (string) $request->route('property'), 'booking' => $this->booking->page($request->attributes->get('online.property'))]);
    }

    public function offers(Request $request): JsonResponse
    {
        $data = $request->validate(['arrival' => ['required', 'string', 'size:10'], 'departure' => ['required', 'string', 'size:10'], 'adults' => ['required', 'integer', 'min:1', 'max:10'], 'children' => ['nullable', 'integer', 'min:0', 'max:10']]);

        return response()->json($this->booking->search($request->attributes->get('online.property'), $data['arrival'], $data['departure'], (int) $data['adults'], (int) ($data['children'] ?? 0)))->header('Cache-Control', 'no-store');
    }

    public function reserve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'arrival' => ['required', 'string', 'size:10'], 'departure' => ['required', 'string', 'size:10'], 'adults' => ['required', 'integer', 'min:1', 'max:10'], 'children' => ['nullable', 'integer', 'min:0', 'max:10'],
            'room_type_id' => ['required', 'string', 'size:26'], 'name' => ['required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:300'], 'agree' => ['required', 'boolean'], 'notice_version' => ['required', 'integer', 'min:0'], 'key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'website' => ['nullable', 'string', 'max:100'],
        ]);

        $done = $this->booking->reserve($request->attributes->get('online.property'), [
            'arrival' => $data['arrival'], 'departure' => $data['departure'], 'adults' => (int) $data['adults'], 'children' => (int) ($data['children'] ?? 0), 'room_type_id' => $data['room_type_id'],
            'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'notes' => $data['notes'] ?? null, 'agree' => (bool) $data['agree'],
            'notice_version' => (int) $data['notice_version'], 'key' => $data['key'], 'website' => $data['website'] ?? null,
        ], app()->getLocale());

        return response()->json($done, 201)->header('Cache-Control', 'no-store');
    }
}
