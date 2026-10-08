<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Presentation\Http\Controllers;

use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\RoomPlan\RoomPlanService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\CheckOutLaundryException;
use App\Modules\FrontOffice\Application\Stays\GuestCorrectionService;
use App\Modules\FrontOffice\Application\Stays\StayAmendmentService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Application\Stays\StayTimeFeeService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Check-in, the guests in the house and check-out. Rules, permissions and privacy live in `StayService`. */
final readonly class StayController
{
    public function __construct(private StayService $stays, private StayAmendmentService $amendments, private GuestCorrectionService $corrections, private StayTimeFeeService $timeFees, private ReservationService $reservations, private CheckOutLaundryException $laundryExceptions, private PropertyContext $property, private RoomPlanService $roomPlans) {}

    public function index(Request $request): Response
    {
        return Inertia::render('front-office/pages/stays', ['stays' => $this->stays->inHouse($this->property->current(), $this->actor($request))]);
    }

    public function show(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $stay = $this->stays->view($property, $this->actor($request), $id);

        return Inertia::render('front-office/pages/stay', [
            'stay' => [...$stay, 'moves' => $this->amendmentMoves($property, $request, $id)],
            'corrections' => $this->corrections->history($property, $this->actor($request), $id),
            'time_fees' => $this->timeFees->assess($property, $this->actor($request), $id),
            'reservation' => $this->summary($this->reservations->find($property, $this->actor($request), $stay['reservation_id'])->toArray()),
        ]);
    }

    public function checkInForm(Request $request, string $id): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);
        $reservation = $this->reservations->find($property, $actor, $id);

        return Inertia::render('front-office/pages/check-in', [
            'reservation' => $this->summary($reservation->toArray()),
            'rooms' => $this->stays->availableRooms($property, $actor, $id),
            'stay' => $this->stays->forReservation($property, $actor, $id),
            'preselect' => preg_match('/^[0-9A-Za-z]{26}$/D', (string) $request->query('room_id')) === 1 ? strtolower((string) $request->query('room_id')) : $this->roomPlans->plannedRoom($property, $actor, $id),
        ]);
    }

    public function lookup(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['id_type' => ['required', 'string', 'max:12'], 'id_number' => ['required', 'string', 'max:40']]);

        return $this->json(['matches' => $this->stays->previousGuests($this->property->current(), $this->actor($request), $id, $data['id_type'], $data['id_number'])]);
    }

    public function checkIn(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'string', 'size:26'], 'full_name' => ['required', 'string', 'max:150'], 'nationality' => ['required', 'string', 'size:2'],
            'id_type' => ['required', 'string', 'max:12'], 'id_number' => ['required', 'string', 'max:40'], 'id_valid_until' => ['nullable', 'string', 'size:10'],
            'visa_number' => ['nullable', 'string', 'max:40'], 'address' => ['required', 'string', 'max:500'],
            'adults' => ['required', 'integer', 'min:1', 'max:40'], 'children' => ['required', 'integer', 'min:0', 'max:40'], 'preferences' => ['nullable', 'string', 'max:500'],
        ]);

        $stay = $this->stays->checkIn(
            $this->property->current(),
            $this->actor($request),
            new CheckInRequest(
                $id, $data['room_id'], $data['full_name'], $data['nationality'], $data['id_type'], $data['id_number'], $data['id_valid_until'] ?? null,
                $data['visa_number'] ?? null, $data['address'], (int) $data['adults'], (int) $data['children'], $data['preferences'] ?? null,
            ),
            IdempotencyKey::fromString((string) $request->header('Idempotency-Key')),
        );

        return $this->json(['stay' => $stay], 201);
    }

    public function preferences(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['preferences' => ['nullable', 'string', 'max:500']]);
        $this->stays->updatePreferences($this->property->current(), $this->actor($request), $id, $data['preferences'] ?? null);

        return $this->json(['saved' => true]);
    }

    public function moveOptions(Request $request, string $id): JsonResponse
    {
        return $this->json(['rooms' => $this->amendments->moveOptions($this->property->current(), $this->actor($request), $id)]);
    }

    public function move(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['room_id' => ['required', 'string', 'size:26'], 'reason' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['stay' => $this->amendments->moveRoom($this->property->current(), $this->actor($request), $id, $data['room_id'], $data['reason'], (int) $data['lock_version'])]);
    }

    public function extensionQuote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['departure' => ['required', 'string', 'size:10']]);

        return $this->json(['quote' => $this->amendments->quoteExtension($this->property->current(), $this->actor($request), $id, $data['departure'])]);
    }

    public function extend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['departure' => ['required', 'string', 'size:10'], 'reason' => ['required', 'string', 'max:300'], 'lock_version' => ['required', 'integer', 'min:0']]);

        return $this->json(['stay' => $this->amendments->extend($this->property->current(), $this->actor($request), $id, $data['departure'], $data['reason'], (int) $data['lock_version'])]);
    }

    public function attachPhoto(Request $request, string $id): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:5120']]);
        $upload = $request->file('photo');
        $file = $this->stays->attachIdPhoto($this->property->current(), $this->actor($request), $id, (string) $upload?->get(), $upload?->getClientOriginalName());

        return $this->json(['file_id' => $file->id], 201);
    }

    public function photo(Request $request, string $id): HttpResponse
    {
        $content = $this->stays->idPhoto($this->property->current(), $this->actor($request), $id);

        return response($content->contents, 200, [
            'Content-Type' => $content->file->mimeType,
            'Content-Disposition' => 'inline; filename="id-photo"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function correct(Request $request, string $id): JsonResponse
    {
        $data = $this->correctionInput($request);
        $history = $this->corrections->correct($this->property->current(), $this->actor($request), $id, $data['changes'], $data['reason'], $data['approval_id'] ?? null);

        return response()->json(['corrections' => $history])->header('Cache-Control', 'no-store');
    }

    public function correctionApproval(Request $request, string $id): JsonResponse
    {
        $data = $this->correctionInput($request);
        $approval = $this->corrections->requestApproval($this->property->current(), $this->actor($request), $id, $data['changes'], $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key')));

        return response()->json(['approval' => $approval->toArray()], 201)->header('Cache-Control', 'no-store');
    }

    /** @return array{changes: array<string, ?string>, reason: string, approval_id?: ?string} */
    private function correctionInput(Request $request): array
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:7'], 'changes.full_name' => ['sometimes', 'nullable', 'string', 'max:150'], 'changes.nationality' => ['sometimes', 'nullable', 'string', 'max:2'],
            'changes.id_type' => ['sometimes', 'nullable', 'string', 'max:12'], 'changes.id_number' => ['sometimes', 'nullable', 'string', 'max:40'], 'changes.id_valid_until' => ['sometimes', 'nullable', 'string', 'size:10'],
            'changes.visa_number' => ['sometimes', 'nullable', 'string', 'max:40'], 'changes.address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'max:300'], 'approval_id' => ['nullable', 'string', 'size:26'],
        ]);

        return $data;
    }

    public function checkOut(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
            'laundry_exception' => ['nullable', 'array'], 'laundry_exception.mode' => ['required_with:laundry_exception', 'string', 'max:12'],
            'laundry_exception.reason' => ['required_with:laundry_exception', 'string', 'max:300'], 'laundry_exception.approval_id' => ['nullable', 'string', 'size:26'],
        ]);
        $exception = isset($data['laundry_exception'])
            ? ['mode' => $data['laundry_exception']['mode'], 'reason' => $data['laundry_exception']['reason'], 'approval_id' => $data['laundry_exception']['approval_id'] ?? null]
            : null;

        return $this->json(['stay' => $this->stays->checkOut($this->property->current(), $this->actor($request), $id, (int) $data['lock_version'], $exception)]);
    }

    /** Opens the approval of settling the laundry in hand as a late charge or a claim (FR-LDY-012). */
    public function laundryExceptionApproval(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'string', 'max:12'], 'reason' => ['required', 'string', 'max:300']]);
        $approval = $this->laundryExceptions->requestApproval($this->property->current(), $this->actor($request), $id, $data['mode'], $data['reason'], IdempotencyKey::fromString((string) $request->header('Idempotency-Key')));

        return response()->json(['approval' => $approval->toArray()], 201)->header('Cache-Control', 'no-store');
    }

    /** @return list<array<string, mixed>> */
    private function amendmentMoves(mixed $property, Request $request, string $id): array
    {
        try {
            return $this->amendments->moves($property, $this->actor($request), $id);
        } catch (Refusal) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $reservation
     * @return array<string, mixed>
     */
    private function summary(array $reservation): array
    {
        unset($reservation['price_snapshot'], $reservation['guest_phone'], $reservation['guest_email']);

        return $reservation;
    }

    public function decideTimeFee(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', 'max:14'], 'action' => ['required', 'string', 'max:6'], 'reason' => ['nullable', 'string', 'max:300']]);

        return $this->json(['time_fees' => $this->timeFees->decide($this->property->current(), $this->actor($request), $id, $data['kind'], $data['action'], $data['reason'] ?? null)]);
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
