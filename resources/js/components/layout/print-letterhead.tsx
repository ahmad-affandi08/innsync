import { useBrand } from '@/shared/lib/brand';

/** The property's logo at the top of every printed page, so a bill, a receipt, a pay slip or a report carries the hotel's own mark. Nothing shows on screen or when no logo was uploaded. */
export function PrintLetterhead() {
    const url = useBrand().logoUrl;

    if (url === null) {
        return null;
    }

    return (
        <div aria-hidden="true" className="hidden px-0 pb-3 print:block" data-testid="print-letterhead">
            <img alt="" className="max-h-16 max-w-[60%] w-auto object-contain" src={url} />
        </div>
    );
}
