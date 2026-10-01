<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Localization;

use App\Shared\Application\Localization\LocaleNegotiator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class SetLocaleController
{
    public function __construct(private LocaleNegotiator $negotiator) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($this->negotiator->supported())],
        ]);

        $request->session()->put((string) config('localization.session_key'), $validated['locale']);

        return redirect()->to($this->returnUrl($request));
    }

    /** Returns to the previous page only when it is on this application's own host. */
    private function returnUrl(Request $request): string
    {
        $previous = url()->previous();
        $host = parse_url($previous, PHP_URL_HOST);

        return is_string($host) && $host === $request->getHost() ? $previous : '/';
    }
}
