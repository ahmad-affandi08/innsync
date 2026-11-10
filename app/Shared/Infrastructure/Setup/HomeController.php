<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Setup;

use App\Shared\Application\Setup\DepartmentEntries;
use App\Shared\Application\Setup\ModuleAccess;
use App\Shared\Application\Setup\ModuleSettings;
use App\Shared\Application\Setup\TodayList;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Where people land. A person who works in one department only starts on that department's page; everyone else sees the home page with everything they can open. `?home=1` always shows the home page. */
final readonly class HomeController
{
    /** @param iterable<TodayTasks> $sources the modules that report work waiting (tagged `today-tasks`) */
    public function __construct(private ModuleAccess $access, private ModuleSettings $modules, private iterable $sources) {}


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
            'tasks' => $this->tasks($request),
        ]);
    }

    /**
     * The work waiting for this person across the departments they work in, in the order of `TodayList`. A module that fails is reported and skipped, so one department can never
     * take the home page down.
     *
     * @return list<array{key: string, count: int, href: string, urgent: bool}>
     */
    private function tasks(Request $request): array
    {
        $propertyId = $request->session()->get('auth.active_property_id');

        if (! is_string($propertyId) || $propertyId === '') {
            return [];
        }

        $property = PropertyId::fromString($propertyId);
        $actor = (string) $request->user()->getAuthIdentifier();
        $tasks = [];

        foreach ($this->sources as $source) {
            try {
                array_push($tasks, ...$source->tasks($property, $actor));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return TodayList::arrange($tasks, $this->modules->disabled($property));
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
