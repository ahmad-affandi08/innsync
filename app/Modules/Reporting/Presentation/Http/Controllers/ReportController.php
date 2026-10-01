<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The report centre, each report and its CSV export. Every rule and permission lives in `ReportService`. */
final readonly class ReportController
{
    public function __construct(private ReportService $reports, private PropertyContext $property) {}

    public function index(Request $request): Response
    {
        $property = $this->property->current();
        $actor = $this->actor($request);

        return Inertia::render('reporting/pages/reports', ['reports' => $this->reports->catalogue($property, $actor), 'context' => $this->reports->context($property, $actor)]);
    }

    public function flash(Request $request): Response
    {
        [$preset, $from, $to] = $this->range($request);

        return Inertia::render('reporting/pages/flash', ['report' => $this->reports->flash($this->property->current(), $this->actor($request), $preset, $from, $to), 'context' => $this->reports->context($this->property->current(), $this->actor($request))]);
    }

    public function movements(Request $request): Response
    {
        $data = $request->validate(['date' => ['nullable', 'string', 'size:10']]);
        $property = $this->property->current();

        return Inertia::render('reporting/pages/movements', [
            'report' => $this->reports->movements($property, $this->actor($request), $data['date'] ?? null),
            'context' => $this->reports->context($property, $this->actor($request)),
            'may_export' => $this->mayExport($request),
        ]);
    }

    public function performance(Request $request): Response
    {
        $data = $request->validate(['by' => ['nullable', 'string', 'max:5'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        [$preset, $from, $to] = $this->range($request);
        $property = $this->property->current();

        return Inertia::render('reporting/pages/performance', [
            'report' => $this->reports->performance($property, $this->actor($request), $data['by'] ?? 'day', $preset, $from, $to, isset($data['year']) ? (int) $data['year'] : null),
            'context' => $this->reports->context($property, $this->actor($request)),
        ]);
    }

    public function exportMovements(Request $request): HttpResponse
    {
        $data = $request->validate(['date' => ['nullable', 'string', 'size:10'], 'purpose' => ['required', 'string', 'max:300']]);

        return $this->download($this->reports->exportMovements($this->property->current(), $this->actor($request), $data['date'] ?? null, $data['purpose']));
    }

    public function exportPerformance(Request $request): HttpResponse
    {
        $data = $request->validate(['by' => ['nullable', 'string', 'max:5'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        [$preset, $from, $to] = $this->range($request);

        return $this->download($this->reports->exportPerformance($this->property->current(), $this->actor($request), $data['by'] ?? 'day', $preset, $from, $to, isset($data['year']) ? (int) $data['year'] : null));
    }

    public function housekeeping(Request $request): Response
    {
        [$preset, $from, $to] = $this->range($request);

        return Inertia::render('reporting/pages/housekeeping', ['report' => $this->reports->housekeeping($this->property->current(), $this->actor($request), $preset, $from, $to)]);
    }

    public function exportHousekeeping(Request $request): HttpResponse
    {
        [$preset, $from, $to] = $this->range($request);

        return $this->download($this->reports->exportHousekeeping($this->property->current(), $this->actor($request), $preset, $from, $to));
    }

    public function laundry(Request $request): Response
    {
        [$preset, $from, $to] = $this->range($request);

        return Inertia::render('reporting/pages/laundry', ['report' => $this->reports->laundry($this->property->current(), $this->actor($request), $preset, $from, $to), 'context' => $this->reports->context($this->property->current(), $this->actor($request))]);
    }

    public function exportLaundry(Request $request): HttpResponse
    {
        [$preset, $from, $to] = $this->range($request);

        return $this->download($this->reports->exportLaundry($this->property->current(), $this->actor($request), $preset, $from, $to));
    }

    public function payments(Request $request): Response
    {
        [$preset, $from, $to] = $this->range($request);

        return Inertia::render('reporting/pages/payments', ['report' => $this->reports->payments($this->property->current(), $this->actor($request), $preset, $from, $to), 'context' => $this->reports->context($this->property->current(), $this->actor($request))]);
    }

    public function registrations(Request $request, bool $foreign = false): Response
    {
        [$preset, $from, $to] = $this->range($request);
        $data = $request->validate(['nationality' => ['nullable', 'string', 'max:2']]);

        return Inertia::render('reporting/pages/registrations', [
            'report' => $this->reports->registrations($this->property->current(), $this->actor($request), $preset, $from, $to, $data['nationality'] ?? null, $foreign),
            'foreign' => $foreign,
            'may_export' => $this->mayExport($request),
        ]);
    }

    public function foreignGuests(Request $request): Response
    {
        return $this->registrations($request, true);
    }

    public function audit(Request $request): Response
    {
        $data = $request->validate(['from' => ['nullable', 'string', 'size:10'], 'to' => ['nullable', 'string', 'size:10'], 'user' => ['nullable', 'string', 'size:26'], 'module' => ['nullable', 'string', 'max:40'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('reporting/pages/audit', ['report' => $this->reports->auditTrail($this->property->current(), $this->actor($request), $data['from'] ?? null, $data['to'] ?? null, $data['user'] ?? null, $data['module'] ?? null, (int) ($data['page'] ?? 1))]);
    }

    public function exportFlash(Request $request): HttpResponse
    {
        [$preset, $from, $to] = $this->range($request);
        $file = $this->reports->exportFlash($this->property->current(), $this->actor($request), $preset, $from, $to);

        return $this->download($file);
    }

    public function exportPayments(Request $request): HttpResponse
    {
        [$preset, $from, $to] = $this->range($request);

        return $this->download($this->reports->exportPayments($this->property->current(), $this->actor($request), $preset, $from, $to));
    }

    public function exportRegistrations(Request $request, bool $foreign = false): HttpResponse
    {
        [$preset, $from, $to] = $this->range($request);
        $data = $request->validate(['nationality' => ['nullable', 'string', 'max:2'], 'purpose' => ['required', 'string', 'max:300']]);

        return $this->download($this->reports->exportRegistrations($this->property->current(), $this->actor($request), $preset, $from, $to, $data['nationality'] ?? null, $foreign, $data['purpose']));
    }

    public function exportForeignGuests(Request $request): HttpResponse
    {
        return $this->exportRegistrations($request, true);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function range(Request $request): array
    {
        $data = $request->validate(['preset' => ['nullable', 'string', 'max:10'], 'from' => ['nullable', 'string', 'size:10'], 'to' => ['nullable', 'string', 'size:10']]);

        return [$data['preset'] ?? null, $data['from'] ?? null, $data['to'] ?? null];
    }

    private function mayExport(Request $request): bool
    {
        return $this->reports->mayExportGuests($this->property->current(), $this->actor($request));
    }

    /** @param array{filename: string, contents: string} $file */
    private function download(array $file): HttpResponse
    {
        return response($file['contents'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }
}
