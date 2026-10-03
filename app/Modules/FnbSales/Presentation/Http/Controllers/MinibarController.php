<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Presentation\Http\Controllers;

use App\Modules\FnbSales\Application\MinibarService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The mini bars of the rooms. Every rule and permission lives in `MinibarService`. */
final readonly class MinibarController
{
    public function __construct(private MinibarService $minibar, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:40'], 'room' => ['nullable', 'string', 'size:26'], 'staff' => ['nullable', 'string', 'size:26'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $property = $this->property->current();
        $actor = $this->actor($request);
        $overview = $this->minibar->overview($property, $actor);
        $operate = $overview['may']['operate'];
        $scanned = null;
        $scanError = null;

        if ($operate && isset($data['code']) && $data['code'] !== '') {
            try {
                $scanned = $this->minibar->room($property, $actor, $data['code']);
            } catch (Refusal $e) {
                $scanError = $e->getMessage();
            }
        }

        return Inertia::render('fnb-sales/pages/minibar', [
            'overview' => $overview, 'scanned' => $scanned, 'scan_error' => $scanError, 'refill' => $operate ? $this->minibar->refillList($property, $actor) : null,
            'history' => $operate ? $this->minibar->history($property, $actor, $data['room'] ?? null, $data['staff'] ?? null, $data['from'] ?? null, $data['to'] ?? null) : null,
        ]);
    }

    public function room(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        return response()->json($this->minibar->room($this->property->current(), $this->actor($request), $data['code']))->header('Cache-Control', 'no-store');
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['room_code' => ['required', 'string', 'max:40'], 'lines' => ['required', 'array', 'max:60'], 'lines.*.item_id' => ['required', 'string', 'size:26'], 'lines.*.consumed' => ['required', 'integer', 'min:0'], 'lines.*.refilled' => ['required', 'integer', 'min:0']]);

        return response()->json($this->minibar->check($this->property->current(), $this->actor($request), $data['room_code'], array_map(static fn (array $l): array => ['item_id' => $l['item_id'], 'consumed' => (int) $l['consumed'], 'refilled' => (int) $l['refilled']], $data['lines'])), 201);
    }

    public function createItem(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string', 'max:80'], 'price_minor' => ['required', 'integer', 'min:1'], 'par_qty' => ['required', 'integer', 'min:1']]);

        return response()->json($this->minibar->createItem($this->property->current(), $this->actor($request), $data['code'], $data['name'], (int) $data['price_minor'], (int) $data['par_qty']), 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'price_minor' => ['required', 'integer', 'min:1'], 'par_qty' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->minibar->updateItem($this->property->current(), $this->actor($request), $id, $data['name'], (int) $data['price_minor'], (int) $data['par_qty'], (int) $data['lock_version']));
    }

    public function itemActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return response()->json($this->minibar->setItemActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version']));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
