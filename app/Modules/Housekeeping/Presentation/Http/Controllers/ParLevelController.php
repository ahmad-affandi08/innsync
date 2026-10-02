<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Presentation\Http\Controllers;

use App\Modules\Housekeeping\Application\ParLevelService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Par levels of linen and amenities, what to bring to the floor and consumption by shift. Every rule lives in `ParLevelService`. */
final readonly class ParLevelController
{
    public function __construct(private ParLevelService $levels, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        return Inertia::render('housekeeping/pages/par-levels', ['overview' => $this->levels->overview($this->property->current(), $this->actor($request))]);
    }

    public function consumption(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'string', 'size:10'], 'shift' => ['required', 'string', 'max:9']]);

        return response()->json(['consumption' => $this->levels->consumption($this->property->current(), $this->actor($request), $data['date'] ?? null, $data['shift'])])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:26'], 'scope_kind' => ['required', 'string', 'max:9'], 'scope_ref' => ['required', 'string', 'max:40'],
            'par_quantity' => ['required', 'integer', 'min:0', 'max:10000'], 'use_quantity' => ['required', 'integer', 'min:0', 'max:10000'], 'lock_version' => ['nullable', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:300'],
        ]);

        return response()->json(['level' => $this->levels->save($this->property->current(), $this->actor($request), $data['item_id'], $data['scope_kind'], $data['scope_ref'], (int) $data['par_quantity'], (int) $data['use_quantity'], isset($data['lock_version']) ? (int) $data['lock_version'] : null, $data['reason'])])->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
