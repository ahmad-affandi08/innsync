<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Catalog\RoomPhotoService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The photos of a room type, for the staff who keep the catalog. The same pictures are shown on the booking page. */
final readonly class RoomTypePhotoController
{
    public function __construct(private RoomPhotoService $photos, private PropertyContext $property) {}

    public function add(Request $request, string $id): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:2100']]);
        $path = $request->file('photo')?->getRealPath();
        $ids = $this->photos->add($this->property->current(), $this->actor($request), $id, $path === false || $path === null ? '' : (string) file_get_contents($path));

        return response()->json(['photos' => $ids], 201)->header('Cache-Control', 'no-store');
    }

    public function remove(Request $request, string $id, string $photo): JsonResponse
    {
        return response()->json(['photos' => $this->photos->remove($this->property->current(), $this->actor($request), $id, $photo)])->header('Cache-Control', 'no-store');
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:6'], 'ids.*' => ['string', 'size:26']]);

        return response()->json(['photos' => $this->photos->order($this->property->current(), $this->actor($request), $id, $data['ids'])])->header('Cache-Control', 'no-store');
    }

    public function picture(Request $request, string $id, string $photo): Response
    {
        $picture = $this->photos->picture($this->property->current(), $photo, $request->query('size') === 'thumb') ?? throw Refusal::notFound('Photo not found.');
        $response = response($picture['content'], 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400']);
        $response->setEtag($picture['sha256']);
        $response->isNotModified($request);

        return $response;
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
