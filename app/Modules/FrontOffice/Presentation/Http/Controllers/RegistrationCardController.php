<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Stays\RegistrationCardService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The registration card to print or sign, its signature image and the house terms. Every rule lives in `RegistrationCardService`. */
final readonly class RegistrationCardController
{
    public function __construct(private RegistrationCardService $cards, private PropertyContext $property) {}

    public function show(Request $request, string $id): Response
    {
        return Inertia::render('front-office/pages/registration-card', ['card' => $this->cards->card($this->property->current(), $this->actor($request), $id)]);
    }

    public function sign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['signature' => ['required', 'string', 'max:400000']]);
        $encoded = preg_replace('#^data:image/png;base64,#', '', $data['signature']) ?? '';
        $png = base64_decode($encoded, true);

        if ($png === false || $png === '') {
            throw Refusal::invalid('The signature is not a valid image.', ['signature']);
        }

        return response()->json(['card' => $this->cards->sign($this->property->current(), $this->actor($request), $id, $png)])->header('Cache-Control', 'no-store');
    }

    public function signature(Request $request, string $id): HttpResponse
    {
        $content = $this->cards->signature($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, ['Content-Type' => $content->file->mimeType, 'Content-Disposition' => 'inline; filename="signature"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function terms(Request $request): Response
    {
        return Inertia::render('front-office/pages/registration-terms', ['catalogue' => $this->cards->terms($this->property->current(), $this->actor($request))]);
    }

    public function defineTerms(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['terms' => $this->cards->defineTerms($this->property->current(), $this->actor($request), $data['body'], $data['reason'])], 201)->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
