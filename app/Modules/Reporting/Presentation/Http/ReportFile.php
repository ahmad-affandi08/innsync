<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http;

use App\Shared\Application\Export\PdfTable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The file of a report as the person asked for it (FR-RPT-003): the spreadsheet (CSV) or, with `format=pdf`, the same table on paper. The rows are the ones of the CSV, so the two files say the same
 * thing and the rules about who may export what, and the audit of it, were applied once, before this point.
 */
final class ReportFile
{
    /** @param array{filename: string, contents: string} $file */
    public static function respond(Request $request, array $file): Response
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $file['filename']);
        $headers = ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff'];

        if ($request->query('format') !== 'pdf') {
            return response($file['contents'], 200, [...$headers, 'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
        }

        $stem = (string) preg_replace('/\.csv$/', '', $name);
        $title = ucfirst(trim((string) preg_replace('/[-_]+/', ' ', $stem)));
        $pdf = PdfTable::fromCsv($title, ['InnSYnc · '.$stem, 'Generated '.now()->utc()->format('Y-m-d H:i').' UTC'], $file['contents']);

        return response($pdf, 200, [...$headers, 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$stem.'.pdf"']);
    }
}
