<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\OnlineBookingAdminService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** How the property takes bookings from its own web page, set by staff. */
final readonly class OnlineBookingStaffController
{
    public function __construct(private OnlineBookingAdminService $admin, private PropertyContext $property) {}

    public function show(Request $request): Response
    {
        return Inertia::render('guest/pages/online-booking', ['settings' => $this->admin->show($this->property->current(), $this->actor($request), $request->getSchemeAndHttpHost())]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'rate_plan_id' => ['nullable', 'string', 'size:26'], 'max_nights' => ['required', 'integer', 'min:1', 'max:60'], 'notify_email' => ['nullable', 'string', 'max:190'], 'notice' => ['nullable', 'string', 'max:500']]);
        $this->admin->save($this->property->current(), $this->actor($request), (bool) $data['enabled'], $data['rate_plan_id'] ?? null, (int) $data['max_nights'], $data['notify_email'] ?? null, $data['notice'] ?? null);

        return response()->json($this->admin->show($this->property->current(), $this->actor($request), $request->getSchemeAndHttpHost()))->header('Cache-Control', 'no-store');
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
