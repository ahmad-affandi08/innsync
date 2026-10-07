<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Setup;

use App\Shared\Application\Setup\SetupChecklist;
use App\Shared\Application\Setup\SetupFacts;
use App\Shared\Application\Tenancy\PropertyContext;
use Inertia\Inertia;
use Inertia\Response;

/** The first-time set-up of the property as one ordered list with what is done and what is missing. Read only: every step opens the screen where it is done. */
final readonly class SetupChecklistController
{
    public function __construct(private SetupFacts $facts, private PropertyContext $property) {}

    public function show(): Response
    {
        $steps = SetupChecklist::evaluate($this->facts->forProperty($this->property->current()));

        return Inertia::render('foundation/pages/setup', ['steps' => $steps, 'progress' => SetupChecklist::progress($steps)]);
    }
}
