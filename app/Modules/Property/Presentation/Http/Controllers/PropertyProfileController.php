<?php

declare(strict_types=1);

namespace App\Modules\Property\Presentation\Http\Controllers;

use App\Modules\Property\Application\Profile\PropertyProfileService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** How the property works: its profile and the optional departments it uses. */
final readonly class PropertyProfileController
{
    public function __construct(private PropertyProfileService $profiles, private PropertyContext $property) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['required', 'string', 'in:hotel,small_resort,villa'],
            'disabled' => ['present', 'array', 'max:20'],
            'disabled.*' => ['string', 'max:32'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $roles = $this->profiles->apply($this->property->current(), strtolower((string) $request->user()->getAuthIdentifier()), $data['profile'], $data['disabled'], $data['reason']);

        return response()->json(['roles' => $roles])->header('Cache-Control', 'no-store');
    }
}
