<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\GuestHelpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** What a guest who proved the stay does besides ordering: ask for something, report a problem, see the bill so far and answer the survey. Every rule lives in `GuestHelpService`. */
final readonly class GuestHelpController
{
    public function __construct(private GuestHelpService $help) {}

    public function help(Request $request): Response
    {
        return Inertia::render('guest/pages/help', ['view' => $this->help->help($this->session($request))]);
    }

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['client_key' => ['required', 'string', 'max:40'], 'category' => ['required', 'string', 'max:20'], 'title' => ['required', 'string', 'max:120'], 'detail' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->help->request($this->session($request), $data['client_key'], $data['category'], $data['title'], $data['detail'] ?? null), 201)->header('Cache-Control', 'no-store');
    }

    public function complaint(Request $request): JsonResponse
    {
        $data = $request->validate(['client_key' => ['required', 'string', 'max:40'], 'summary' => ['required', 'string', 'max:120'], 'detail' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->help->complaint($this->session($request), $data['client_key'], $data['summary'], $data['detail'] ?? null), 201)->header('Cache-Control', 'no-store');
    }

    public function bill(Request $request): Response
    {
        return Inertia::render('guest/pages/bill', ['view' => $this->help->bill($this->session($request))]);
    }

    public function survey(Request $request): Response
    {
        return Inertia::render('guest/pages/survey', ['view' => $this->help->survey($this->session($request))]);
    }

    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'overall' => ['required', 'integer', 'min:1', 'max:5'], 'room_rating' => ['nullable', 'integer', 'min:1', 'max:5'], 'service_rating' => ['nullable', 'integer', 'min:1', 'max:5'], 'food_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'value_rating' => ['nullable', 'integer', 'min:1', 'max:5'], 'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $ratings = ['overall' => (int) $data['overall']];

        foreach (GuestHelpService::SURVEY_FIELDS as $field) {
            $ratings[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }

        return response()->json($this->help->answer($this->session($request), $ratings, $data['comment'] ?? null), 201)->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function session(Request $request): array
    {
        /** @var array<string, mixed> $session */
        $session = $request->attributes->get('guest.session');

        return $session;
    }
}
