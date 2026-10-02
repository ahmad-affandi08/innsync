import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/shared/i18n/i18n';

type Props = { path: string; preset: string; from: string; to: string; extra?: Record<string, string> };

const PRESETS = ['today', 'yesterday', 'last7', 'month'] as const;

/** One period for the whole page (FR-DSH-015): a preset or a custom range, kept in the address so it can be shared and reloaded. */
export function PeriodPicker({ extra = {}, from, path, preset, to }: Props) {
    const { t } = useTranslation();
    const [custom, setCustom] = useState({ from, to });
    const go = (query: Record<string, string>) => router.get(path, { ...extra, ...query }, { preserveScroll: true });

    return (
        <div className="flex flex-wrap items-end gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
            <div aria-label={t('rpt.period.label')} className="flex flex-wrap items-center" role="group">
                {PRESETS.map((p) => (
                    <Button aria-pressed={preset === p} className="-ml-px first:ml-0" key={p} onClick={() => go({ preset: p })} size="sm" type="button" variant={preset === p ? 'default' : 'outline'}>{t(`rpt.period.${p}` as 'rpt.period.today')}</Button>
                ))}
            </div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); go({ from: custom.from, to: custom.to }); }}>
                <FormField label={t('rpt.period.from')}><Input className="min-h-8 py-1" onChange={(e) => setCustom({ ...custom, from: e.target.value })} required type="date" value={custom.from} /></FormField>
                <FormField label={t('rpt.period.to')}><Input className="min-h-8 py-1" onChange={(e) => setCustom({ ...custom, to: e.target.value })} required type="date" value={custom.to} /></FormField>
                <Button size="sm" type="submit" variant={preset === 'custom' ? 'default' : 'outline'}>{t('rpt.period.apply')}</Button>
            </form>
        </div>
    );
}
