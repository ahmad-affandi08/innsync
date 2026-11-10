<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Setup\StarterData;
use App\Shared\Domain\Tenancy\PropertyId;

/** What HR starts a property with: the usual shifts, kinds of leave and pay, and the appraisal form (each already had a button on its own page). */
final readonly class HumanResourceStarterData implements StarterData
{
    public function __construct(private RosterService $roster, private LeaveTypeService $leave, private PayComponentService $pay, private AppraisalService $appraisals) {}

    public function parts(): array
    {
        return [
            ['module' => 'hr', 'label' => 'shifts', 'install' => fn (PropertyId $p, string $a): int => count($this->roster->baseline($p, $a))],
            ['module' => 'hr', 'label' => 'kinds of leave', 'install' => fn (PropertyId $p, string $a): int => count($this->leave->baseline($p, $a))],
            ['module' => 'hr', 'label' => 'kinds of pay', 'install' => fn (PropertyId $p, string $a): int => count($this->pay->baseline($p, $a))],
            ['module' => 'hr', 'label' => 'appraisal form', 'install' => fn (PropertyId $p, string $a): int => $this->appraisals->installBaseline($p, $a)],
        ];
    }
}
