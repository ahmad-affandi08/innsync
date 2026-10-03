<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Presentation\Http\Controllers;

use App\Modules\HumanResource\Application\PayslipService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Payslips and the payroll file. Every rule and permission lives in `PayslipService`. */
final readonly class PayslipController
{
    public function __construct(private PayslipService $slips, private PropertyContext $property) {}

    public function mine(Request $request): Response
    {
        return Inertia::render('hr/pages/payslips', ['payslips' => $this->slips->mine($this->property->current(), $this->actor($request))]);
    }

    public function own(Request $request, string $id): Response
    {
        return Inertia::render('hr/pages/payslip', ['slip' => $this->slips->ownSlip($this->property->current(), $this->actor($request), $id), 'mine' => true]);
    }

    public function of(Request $request, string $id, string $employee): Response
    {
        return Inertia::render('hr/pages/payslip', ['slip' => $this->slips->slipOf($this->property->current(), $this->actor($request), $id, $employee), 'mine' => false]);
    }

    public function export(Request $request, string $id): HttpResponse
    {
        $file = $this->slips->export($this->property->current(), $this->actor($request), $id);

        return response($file['contents'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
