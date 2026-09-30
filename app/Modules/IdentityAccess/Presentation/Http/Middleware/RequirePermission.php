<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Middleware;

use App\Modules\IdentityAccess\Application\Authorization\ScopedAuthorizer;
use App\Shared\Application\Tenancy\PropertyContext;
use Closure;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequirePermission
{
    public function __construct(
        private ScopedAuthorizer $authorizer,
        private PropertyContext $propertyContext,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(
        Request $request,
        Closure $next,
        string $permission,
        string $scopeType = 'property',
        ?string $routeParameter = null,
    ): Response {
        abort_unless(in_array($scopeType, ['property', 'outlet', 'department'], true), 404);
        $scopeId = $scopeType === 'property'
            ? null
            : $this->routeScopeId($request, $routeParameter);

        $allowed = $this->authorizer->allows(
            (string) $request->user()->getAuthIdentifier(),
            $permission,
            $this->propertyContext->current()->toString(),
            $scopeType,
            $scopeId,
        );

        abort_unless($allowed, 403);

        return $next($request);
    }

    private function routeScopeId(Request $request, ?string $routeParameter): string
    {
        abort_if($routeParameter === null, 404);

        $value = $request->route($routeParameter);

        if ($value instanceof UrlRoutable) {
            return (string) $value->getRouteKey();
        }

        abort_unless(is_string($value), 404);

        return $value;
    }
}
