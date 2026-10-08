<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Branding\PropertyBranding;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The property's logo: the screen where it is set, the picture itself for signed-in staff, and the two changes. */
final readonly class PropertyLogoController
{
    public function __construct(private PropertyBranding $branding, private PropertyContext $property) {}

    public function show(): Response
    {
        return Inertia::render('foundation/pages/branding', $this->branding->show($this->property->current()));
    }

    /** The picture, for the header and printed documents. The address carries the fingerprint, so a changed logo is fetched again and an unchanged one is kept by the browser. */
    public function picture(): HttpResponse
    {
        $logo = $this->branding->picture($this->property->current()) ?? throw Refusal::notFound('No logo.');
        $headers = ['Content-Type' => $logo['mime'], 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox", 'Cache-Control' => 'private, max-age=86400', 'ETag' => '"'.$logo['sha256'].'"'];

        return response($logo['content'], 200, $headers);
    }

    public function replace(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'file', 'max:600']]);
        $path = $request->file('logo')?->getRealPath();
        $this->branding->replace($this->property->current(), (string) $request->user()->getAuthIdentifier(), $path === false || $path === null ? '' : (string) file_get_contents($path));

        return response()->json($this->branding->show($this->property->current()))->header('Cache-Control', 'no-store');
    }

    public function remove(Request $request): JsonResponse
    {
        $this->branding->remove($this->property->current(), (string) $request->user()->getAuthIdentifier());

        return response()->json($this->branding->show($this->property->current()))->header('Cache-Control', 'no-store');
    }
}
