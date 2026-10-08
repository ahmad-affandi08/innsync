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

    /** The picture, for the header, the sign-in page, guest pages and printed documents. The address carries the fingerprint, so a changed logo is fetched again and an unchanged one is kept by the browser. */
    public function picture(Request $request, string $property): HttpResponse
    {
        $logo = $this->branding->pictureOf($property) ?? throw Refusal::notFound('No logo.');
        $response = response($logo['content'], 200, ['Content-Type' => $logo['mime'], 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox", 'Cache-Control' => 'public, max-age=86400']);
        $response->setEtag($logo['sha256']);
        $response->isNotModified($request);

        return $response;
    }

    public function poweredBy(Request $request): JsonResponse
    {
        $data = $request->validate(['show' => ['required', 'boolean']]);
        $this->branding->setPoweredBy($this->property->current(), (string) $request->user()->getAuthIdentifier(), (bool) $data['show']);

        return response()->json($this->branding->show($this->property->current()))->header('Cache-Control', 'no-store');
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
