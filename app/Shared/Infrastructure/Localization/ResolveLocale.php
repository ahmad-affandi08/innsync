<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Localization;

use App\Shared\Application\Localization\LocaleNegotiator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the request locale before controllers, validation and the error
 * envelope produce text. Runs after session start so a language chosen through
 * the switcher applies to staff and guests alike.
 */
final readonly class ResolveLocale
{
    public function __construct(private LocaleNegotiator $negotiator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = $request->hasSession()
            ? $request->session()->get((string) config('localization.session_key'))
            : null;

        $locale = $this->negotiator->resolve(
            is_string($chosen) ? $chosen : null,
            $request->header('Accept-Language'),
        );

        app()->setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', 'Accept-Language', false);

        return $response;
    }
}
