import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/select';
import { lastPage } from '@/shared/table/table-query-state';

export type PaginationLabels = {
    /** Accessible name of the pagination landmark. */
    navigation: string;
    previous: string;
    next: string;
    perPage: string;
    /** Text such as "1–25 of 140"; built by the screen so it can be localized. */
    summary: (range: { from: number; to: number; total: number }) => string;
    /** Announced when the page changes, e.g. "Page 2 of 6". */
    pageStatus: (page: number, pages: number) => string;
};

type PaginationProps = {
    page: number;
    perPage: number;
    perPageOptions: readonly number[];
    /** Server-reported total; pagination never infers it from loaded rows. */
    rowCount: number;
    labels: PaginationLabels;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
    disabled?: boolean;
};

function Pagination({
    disabled = false,
    labels,
    onPageChange,
    onPerPageChange,
    page,
    perPage,
    perPageOptions,
    rowCount,
}: PaginationProps) {
    const pages = lastPage(rowCount, perPage);
    const from = rowCount === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, rowCount);

    return (
        <nav
            aria-label={labels.navigation}
            className="flex flex-wrap items-center justify-between gap-3 text-sm"
        >
            <p className="text-muted-foreground">
                {labels.summary({ from, to, total: rowCount })}
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2 text-muted-foreground">
                    <span>{labels.perPage}</span>
                    <Select
                        className="min-h-10 w-20"
                        disabled={disabled}
                        onChange={(event) =>
                            onPerPageChange(Number(event.target.value))
                        }
                        value={perPage}
                    >
                        {perPageOptions.map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </Select>
                </label>
                <Button
                    aria-label={labels.previous}
                    disabled={disabled || page <= 1}
                    onClick={() => onPageChange(page - 1)}
                    size="icon"
                    type="button"
                    variant="outline"
                >
                    <ChevronLeft aria-hidden="true" className="size-4" />
                </Button>
                <span aria-live="polite" className="tabular-nums">
                    {labels.pageStatus(page, pages)}
                </span>
                <Button
                    aria-label={labels.next}
                    disabled={disabled || page >= pages}
                    onClick={() => onPageChange(page + 1)}
                    size="icon"
                    type="button"
                    variant="outline"
                >
                    <ChevronRight aria-hidden="true" className="size-4" />
                </Button>
            </div>
        </nav>
    );
}

export { Pagination };
