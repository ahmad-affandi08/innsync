<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Setup;

use App\Shared\Application\Setup\DepartmentEntries;
use App\Shared\Application\Setup\ModuleAccess;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Where people land. A person who works in one department only starts on that department's page; everyone else sees the home page with everything they can open. `?home=1` always shows the home page. */
final readonly class HomeController
{
    public function __construct(private ModuleAccess $access) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->boolean('home')) {
            $landing = $this->landing($request);

            if ($landing !== null) {
                return redirect($landing);
            }
        }

        return Inertia::render('foundation/pages/welcome', [
            'appVersion' => (string) config('app.version'),
            'userName' => (string) $request->user()->name,
            'activePropertyId' => (string) $request->session()->get('auth.active_property_id'),
        ]);
    }

    private function landing(Request $request): ?string
    {
        $property = $request->session()->get('auth.active_property_id');

        if (! is_string($property) || $property === '') {
            return null;
        }

        try {
            $keys = array_values(array_diff($this->access->forUser((string) $request->user()->getAuthIdentifier(), PropertyId::fromString($property)), ['home', 'approvals']));
        } catch (Throwable) {
            return null;
        }

        return count($keys) === 1 ? DepartmentEntries::landing($keys[0]) : null;
    }
}
