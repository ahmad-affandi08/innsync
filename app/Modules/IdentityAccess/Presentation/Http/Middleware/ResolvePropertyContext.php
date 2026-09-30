<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Middleware;

use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use App\Shared\Application\Tenancy\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolvePropertyContext
{
    public function __construct(
        private UserAccessReader $accessReader,
        private PropertyContext $propertyContext,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $propertyId = $request->session()->get('auth.active_property_id');
        $userId = (string) $request->user()->getAuthIdentifier();

        if (! is_string($propertyId)
            || ! $this->accessReader->hasPropertyAccess($userId, $propertyId)) {
            $request->session()->forget('auth.active_property_id');

            return redirect()->route('properties.select');
        }

        $this->propertyContext->activateFromString($propertyId);

        try {
            return $next($request);
        } finally {
            $this->propertyContext->clear();
        }
    }
}
