import { router, usePage } from '@inertiajs/react';

import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import type { MessageKey } from '@/locales/en/index';
import { useTranslation } from '@/shared/i18n/i18n';

export type FilterOptions = {
    filters: string[];
    staff: { id: string; name: string }[];
    departments: string[];
    outlets: { id: string; code: string; name: string }[];
};

const NAMES = ['user', 'department', 'outlet'] as const;

/** The filters of a report that are in the address (FR-RPT-002), so that the period, the exports and the filters stay together. */
export function useReportFilters(): { values: Record<string, string>; query: string } {
    const { url } = usePage();
    const params = new URLSearchParams(url.includes('?') ? url.slice(url.indexOf('?') + 1) : '');
    const values: Record<string, string> = {};

    for (const name of NAMES) {
        const value = params.get(name);
        if (value !== null && value !== '') values[name] = value;
    }

    return { values, query: new URLSearchParams(values).toString() };
}

/** The filters by person, department and outlet that a report takes, kept in the address next to the period. */
export function ReportFilterBar({ options, path }: { options: FilterOptions; path: string }) {
    const { t } = useTranslation();
    const { url } = usePage();

    if (options.filters.length === 0) return null;

    function change(name: string, value: string) {
        const params = Object.fromEntries(new URLSearchParams(url.includes('?') ? url.slice(url.indexOf('?') + 1) : ''));
        if (value === '') delete params[name]; else params[name] = value;
        router.get(path, params, { preserveScroll: true });
    }

    const current = (name: string) => new URLSearchParams(url.includes('?') ? url.slice(url.indexOf('?') + 1) : '').get(name) ?? '';

    return (
        <div className="flex flex-wrap items-end gap-3 border border-border bg-surface p-4 print:hidden" data-testid="report-filters">
            {options.filters.includes('user') ? (
                <FormField label={t('rpt.filter.user')}>
                    <Select onChange={(e) => change('user', e.target.value)} value={current('user')}>
                        <option value="">{t('rpt.filter.all')}</option>
                        {options.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                </FormField>
            ) : null}
            {options.filters.includes('department') ? (
                <FormField label={t('rpt.filter.department')}>
                    <Select onChange={(e) => change('department', e.target.value)} value={current('department')}>
                        <option value="">{t('rpt.filter.all')}</option>
                        {options.departments.map((d) => <option key={d} value={d}>{t(`inv.dept.${d}` as MessageKey)}</option>)}
                    </Select>
                </FormField>
            ) : null}
            {options.filters.includes('outlet') ? (
                <FormField label={t('rpt.filter.outlet')}>
                    <Select onChange={(e) => change('outlet', e.target.value)} value={current('outlet')}>
                        <option value="">{t('rpt.filter.all')}</option>
                        {options.outlets.map((o) => <option key={o.id} value={o.id}>{o.code} · {o.name}</option>)}
                    </Select>
                </FormField>
            ) : null}
        </div>
    );
}
