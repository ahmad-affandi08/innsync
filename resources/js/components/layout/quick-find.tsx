import { Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Dialog } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { MessageKey } from '@/locales/en/index';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Found = { id: string; number: string; status: string; guest_name: string; arrival: string; departure: string };

/** One box to find a reservation from any screen, by number, guest name or phone. Opens with Ctrl+K (Cmd+K on a Mac). */
export function QuickFind() {
    const { t } = useTranslation();
    const format = useFormatters();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Found[] | null>(null);
    const [failed, setFailed] = useState(false);
    const latest = useRef(0);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        const text = query.trim();
        if (text.length < 2) {
            setResults(null);
            setFailed(false);
            return undefined;
        }

        const ticket = ++latest.current;
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(`/front-office/reservations/find?q=${encodeURIComponent(text)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (ticket !== latest.current) return;
                if (!response.ok) throw new Error(String(response.status));
                setResults(((await response.json()) as { results: Found[] }).results);
                setFailed(false);
            } catch {
                if (ticket === latest.current) setFailed(true);
            }
        }, 250);

        return () => window.clearTimeout(timer);
    }, [query]);

    function close() {
        setOpen(false);
        setQuery('');
        setResults(null);
        setFailed(false);
    }

    return (
        <>
            <button aria-label={t('find.open')} className="inline-flex h-9 items-center gap-2 border border-border bg-surface px-2.5 text-sm text-muted-foreground hover:bg-surface-muted" onClick={() => setOpen(true)} title={t('find.open')} type="button">
                <Search aria-hidden="true" className="size-4" />
                <span className="hidden xl:inline">{t('find.open')}</span>
            </button>
            <Dialog onClose={close} open={open} title={t('find.title')}>
                <div className="flex flex-col gap-3">
                    <Input autoFocus onChange={(e) => setQuery(e.target.value)} placeholder={t('find.placeholder')} value={query} />
                    {failed ? <p className="text-sm text-danger">{t('find.failed')}</p> : null}
                    {results !== null && results.length === 0 && !failed ? <p className="text-sm text-muted-foreground">{t('find.none')}</p> : null}
                    {results !== null && results.length > 0 ? (
                        <ul className="max-h-80 divide-y divide-border overflow-y-auto border-y border-border" data-testid="find-results">
                            {results.map((r) => (
                                <li key={r.id}>
                                    <Link className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 px-1 py-2 hover:bg-surface-muted" href={`/front-office/reservations/${r.id}`} onClick={close}>
                                        <span className="min-w-0"><span className="font-medium">{r.guest_name}</span> <span className="text-sm text-muted-foreground">{r.number}</span></span>
                                        <span className="text-xs text-muted-foreground">{format.date(r.arrival)} – {format.date(r.departure)} · {t(`fo.status.${r.status}` as MessageKey)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <p className="text-xs text-muted-foreground">{t('find.hint')}</p>
                </div>
            </Dialog>
        </>
    );
}
