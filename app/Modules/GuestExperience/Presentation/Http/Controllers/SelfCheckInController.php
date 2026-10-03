<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\SelfCheckInLinkService;
use App\Modules\GuestExperience\Application\SelfCheckInService;
use App\Shared\Application\Errors\Refusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What a guest does on a self check-in link: sees the page, gives the reservation number at the lobby, sends the form. Every rule lives in the services. */
final readonly class SelfCheckInController
{
    public function __construct(private SelfCheckInService $checkins, private SelfCheckInLinkService $links) {}

    public function show(Request $request, string $token): Response
    {
        return Inertia::render('guest/pages/checkin', ['token' => $token, 'view' => $this->checkins->open($this->link($request))]);
    }

    public function find(Request $request): JsonResponse
    {
        $data = $request->validate(['reservation_number' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:150']]);

        return response()->json($this->links->lookup($this->link($request), $data['reservation_number'], $data['name']));
    }

    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['nullable', 'file', 'max:5120'], 'signature' => ['nullable', 'string', 'max:400000'], 'notice_version' => ['required', 'integer', 'min:0'], 'locale' => ['nullable', 'string', 'max:2'],
            'full_name' => ['nullable', 'string', 'max:200'], 'nationality' => ['nullable', 'string', 'max:10'], 'id_type' => ['nullable', 'string', 'max:12'], 'id_number' => ['nullable', 'string', 'max:60'], 'id_valid_until' => ['nullable', 'string', 'max:10'],
            'visa_number' => ['nullable', 'string', 'max:60'], 'address' => ['nullable', 'string', 'max:600'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'string', 'max:200'], 'adults' => ['nullable', 'integer'], 'children' => ['nullable', 'integer'],
            'deposit_reference' => ['nullable', 'string', 'max:80'],
        ]);

        $upload = $request->file('photo');
        $encoded = preg_replace('#^data:image/png;base64,#', '', (string) $request->input('signature', '')) ?? '';
        $signature = $encoded === '' ? '' : base64_decode($encoded, true);

        if ($signature === false) {
            throw Refusal::invalid('The signature is not a valid image.', ['signature']);
        }

        $view = $this->checkins->submit($this->link($request), [
            ...$request->only(['full_name', 'nationality', 'id_type', 'id_number', 'id_valid_until', 'visa_number', 'address', 'phone', 'email', 'adults', 'children', 'deposit_reference', 'locale', 'notice_version']),
            'agree' => $request->boolean('agree'), 'deposit_claimed' => $request->boolean('deposit_claimed'),
            'photo' => $upload === null ? '' : (string) $upload->get(), 'photo_name' => $upload?->getClientOriginalName(), 'signature' => $signature,
        ]);

        return response()->json(['view' => $view], 201);
    }

    /** @return array<string, mixed> */
    private function link(Request $request): array
    {
        /** @var array<string, mixed> $link */
        $link = $request->attributes->get('guest.link');

        return $link;
    }
}
