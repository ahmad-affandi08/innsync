<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\OutletService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The outlets of the hotel and the posting sources each owns. Every rule lives in `OutletService`. */
final readonly class OutletController
{
    public function __construct(private OutletService $outlets, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('reporting/pages/outlets', ['overview' => $this->outlets->overview($this->property->current(), $this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:60'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['outlet' => $this->outlets->create($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['reason'])], 201)->header('Cache-Control', 'no-store');
    }

    public function rename(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['outlet' => $this->outlets->rename($this->property->current(), $this->actor($request), $id, $data['name'], (int) $data['lock_version'], $data['reason'])])->header('Cache-Control', 'no-store');
    }

    public function addSource(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['source' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['outlet' => $this->outlets->addSource($this->property->current(), $this->actor($request), $id, $data['source'], $data['reason'])])->header('Cache-Control', 'no-store');
    }

    public function removeSource(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['source' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:300']]);

        return response()->json(['outlet' => $this->outlets->removeSource($this->property->current(), $this->actor($request), $id, $data['source'], $data['reason'])])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
