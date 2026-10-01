<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\DTOs\AuthorizedProperty;
use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Modules\IdentityAccess\Presentation\Http\Requests\SelectPropertyRequest;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PropertySelectionController
{
    public function create(Request $request, UserAccessReader $accessReader): Response
    {
        return Inertia::render('identity-access/pages/property-select', [
            'properties' => array_map(
                static fn (AuthorizedProperty $property): array => [
                    'id' => $property->id,
                    'name' => $property->name,
                ],
                $accessReader->authorizedProperties((string) $request->user()->getAuthIdentifier()),
            ),
        ]);
    }

    public function store(
        SelectPropertyRequest $request,
        UserAccessReader $accessReader,
        SecurityLog $securityLog,
    ): RedirectResponse {
        $propertyId = $request->string('property_id')->toString();
        $userId = (string) $request->user()->getAuthIdentifier();

        if (! $accessReader->hasPropertyAccess($userId, $propertyId)) {
            $securityLog->record(new SecurityEvent(
                IdentityAccessSecurityEvent::PropertySelection->value,
                SecurityEventOutcome::Denied,
                $userId,
                metadata: ['requested_property_id' => strtolower($propertyId)],
            ));

            throw ValidationException::withMessages([
                'property_id' => 'You do not have access to this property.',
            ]);
        }

        $securityLog->record(new SecurityEvent(
            IdentityAccessSecurityEvent::PropertySelection->value,
            SecurityEventOutcome::Success,
            $userId,
            metadata: ['selected_property_id' => strtolower($propertyId)],
        ));
        $request->session()->put('auth.active_property_id', strtolower($propertyId));

        return redirect()->route('home');
    }
}
