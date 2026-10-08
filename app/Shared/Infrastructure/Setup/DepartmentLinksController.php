<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Setup;

use App\Shared\Application\Setup\DepartmentEntries;
use App\Shared\Application\Setup\ModuleSettings;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The address to give each department's staff, and the guest-facing addresses, in one place to copy from. Read only. */
final readonly class DepartmentLinksController
{
    public function __construct(private ModuleSettings $modules, private PropertyContext $property) {}

    public function show(Request $request): Response
    {
        $property = $this->property->current();
        $off = $this->modules->disabled($property);

        return Inertia::render('foundation/pages/department-links', [
            'base' => $request->getSchemeAndHttpHost(),
            'departments' => array_values(array_filter(DepartmentEntries::all(), static fn (array $d): bool => ! in_array($d['key'], $off, true))),
            'guest' => ['rooms' => '/guest/qr', 'booking' => '/book/'.$property->toString()],
        ]);
    }
}
