<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Rates\RateQuoteService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Rate plans, prices, restrictions and the quote preview. Rules and permissions live in the application services. */
final readonly class RatePlanController
{
    public function __construct(
        private RatePlanService $plans,
        private RoomCatalogService $catalog,
        private RateQuoteService $quotes,
        private PropertyContext $property,
    ) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);
        $plans = $this->plans->listPlans($property, $actor);
        $selected = null;
        $planId = strtolower((string) $request->query('plan', ''));

        foreach ($plans as $plan) {
            if ($plan->id === $planId) {
                $selected = [
                    'plan' => $plan->toArray(),
                    'periods' => array_map(static fn ($p): array => [
                        'id' => $p->id, 'room_type_id' => $p->roomTypeId, 'from' => $p->from->toString(), 'to' => $p->to->toString(),
                        'weekday_mask' => $p->weekdays->mask, 'nightly_minor' => $p->nightly->amountMinor, 'currency' => $p->nightly->currency,
                    ], $this->plans->listPeriods($property, $actor, $plan->id)),
                    'restrictions' => array_map(static fn ($r): array => [
                        'id' => $r->id, 'room_type_id' => $r->roomTypeId, 'from' => $r->from->toString(), 'to' => $r->to->toString(),
                        'min_stay' => $r->minStay, 'max_stay' => $r->maxStay, 'closed_to_arrival' => $r->closedToArrival,
                        'closed_to_departure' => $r->closedToDeparture, 'stop_sell' => $r->stopSell,
                    ], $this->plans->listRestrictions($property, $actor, $plan->id)),
                ];
            }
        }

        return Inertia::render('property/pages/rate-plans', [
            'plans' => array_map(static fn ($p): array => $p->toArray(), $plans),
            'types' => array_map(static fn ($t): array => $t->toArray(), $this->catalog->activeTypes($property)),
            'selected' => $selected,
        ]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:100'], 'kind' => ['required', 'string', 'max:20'],
            'inclusions' => ['nullable', 'string', 'max:500'], 'prices_include_charges' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500'],
        ]);

        $plan = $this->plans->createPlan($this->property->current(), $this->actor($request), $data['code'], $data['name'], $data['kind'], $data['inclusions'] ?? null, (bool) $data['prices_include_charges'], $data['reason']);

        return $this->json(['plan' => $plan->toArray()], 201);
    }

    public function updatePlan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'kind' => ['required', 'string', 'max:20'], 'inclusions' => ['nullable', 'string', 'max:500'],
            'prices_include_charges' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500'],
        ]);

        $plan = $this->plans->updatePlan($this->property->current(), $this->actor($request), $id, $data['name'], $data['kind'], $data['inclusions'] ?? null, (bool) $data['prices_include_charges'], (int) $data['lock_version'], $data['reason']);

        return $this->json(['plan' => $plan->toArray()]);
    }

    public function planActive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);

        $plan = $this->plans->setPlanActive($this->property->current(), $this->actor($request), $id, (bool) $data['active'], (int) $data['lock_version'], $data['reason']);

        return $this->json(['plan' => $plan->toArray()]);
    }

    public function addPrice(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'string', 'size:26'], 'from' => ['required', 'string', 'size:10'], 'to' => ['required', 'string', 'size:10'],
            'weekday_mask' => ['required', 'integer', 'min:1', 'max:127'], 'nightly_minor' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500'],
        ]);

        $period = $this->plans->addPrice($this->property->current(), $this->actor($request), $id, $data['room_type_id'], $data['from'], $data['to'], (int) $data['weekday_mask'], (int) $data['nightly_minor'], $data['reason']);

        return $this->json(['id' => $period->id], 201);
    }

    public function repriceNight(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['nightly_minor' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);

        $period = $this->plans->repriceNight($this->property->current(), $this->actor($request), $id, (int) $data['nightly_minor'], $data['reason']);

        return $this->json(['id' => $period->id]);
    }

    public function removePrice(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->plans->removePrice($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['removed' => true]);
    }

    public function addRestriction(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['nullable', 'string', 'size:26'], 'from' => ['required', 'string', 'size:10'], 'to' => ['required', 'string', 'size:10'],
            'min_stay' => ['nullable', 'integer', 'min:1', 'max:365'], 'max_stay' => ['nullable', 'integer', 'min:1', 'max:365'],
            'closed_to_arrival' => ['required', 'boolean'], 'closed_to_departure' => ['required', 'boolean'], 'stop_sell' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500'],
        ]);

        $restriction = $this->plans->addRestriction(
            $this->property->current(), $this->actor($request), $id, $data['room_type_id'] ?? null, $data['from'], $data['to'],
            isset($data['min_stay']) ? (int) $data['min_stay'] : null, isset($data['max_stay']) ? (int) $data['max_stay'] : null,
            (bool) $data['closed_to_arrival'], (bool) $data['closed_to_departure'], (bool) $data['stop_sell'], $data['reason'],
        );

        return $this->json(['id' => $restriction->id], 201);
    }

    public function removeRestriction(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->plans->removeRestriction($this->property->current(), $this->actor($request), $id, $data['reason']);

        return $this->json(['removed' => true]);
    }

    public function quote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['room_type_id' => ['required', 'string', 'size:26'], 'arrival' => ['required', 'string', 'size:10'], 'departure' => ['required', 'string', 'size:10']]);
        $property = $this->property->current();
        // Viewing prices is what this screen needs; the permission check is the same one that lists them.
        $this->plans->listPlans($property, $this->actor($request));

        return $this->json(['quote' => $this->quotes->describe($property, $id, $data['room_type_id'], $data['arrival'], $data['departure'])]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'no-store');
    }
}
