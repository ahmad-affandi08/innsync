<?php

declare(strict_types=1);

namespace App\Shared\Application\Import;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Transactions\TransactionRunner;

/**
 * Runs one create per CSV row, all or nothing. Every row is tried even after one fails, so the person gets the whole list of rows to fix at once. A check
 * (dry run) tries every row for real and then undoes them all; an import that finds any bad row undoes them all too. The rows go through the same service
 * a person uses on screen, so every rule, permission and audit entry applies to an imported row as to a typed one.
 */
final readonly class BatchImport
{
    public function __construct(private TransactionRunner $transactions) {}

    /**
     * @param  array{rows: list<array{line: int, values: array<string, string>}>, errors: list<array{line: int, code: string, message: string}>}  $parsed
     * @param  callable(array<string, string>): void  $create  throws Refusal for a row that is refused
     * @return array{status: 'applied'|'validated'|'rejected', dry_run: bool, count: int, errors: list<array{line: int, code: string, message: string}>}
     */
    public function run(array $parsed, callable $create, bool $dryRun): array
    {
        if ($parsed['errors'] !== []) {
            return ['status' => 'rejected', 'dry_run' => $dryRun, 'count' => 0, 'errors' => $parsed['errors']];
        }

        $errors = [];

        try {
            $this->transactions->run(function () use ($parsed, $create, $dryRun, &$errors): void {
                foreach ($parsed['rows'] as $row) {
                    try {
                        $create($row['values']);
                    } catch (Refusal $refusal) {
                        if ($refusal->status() === 403) {
                            throw $refusal; // not allowed at all: nothing to list row by row
                        }

                        $errors[] = ['line' => $row['line'], 'code' => 'refused', 'message' => $refusal->getMessage()];
                    }
                }

                if ($errors !== [] || $dryRun) {
                    throw new ImportUndone;
                }
            });
        } catch (ImportUndone) {
            // every row is undone on purpose; what is left to say is in $errors
        }

        if ($errors !== []) {
            return ['status' => 'rejected', 'dry_run' => $dryRun, 'count' => 0, 'errors' => $errors];
        }

        return ['status' => $dryRun ? 'validated' : 'applied', 'dry_run' => $dryRun, 'count' => count($parsed['rows']), 'errors' => []];
    }
}
