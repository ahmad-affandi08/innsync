import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { bpToInput, minorToInput, parsePercentToBp } from '@/modules/inventory-purchasing/lib/amounts';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Settings = { budget_policy: string; tax_bp: number; po_tolerance_bp: number; over_receipt_bp: number; invoice_price_tolerance_bp: number; invoice_qty_tolerance_bp: number; lock_version: number };
type Budget = { id: string; department: string; period: string; amount_minor: number; lock_version: number };
type Overview = { currency: string; settings: Settings; policies: string[]; departments: string[]; budgets: Budget[]; may: { manage: boolean } };
type PolicyForm = { budget_policy: string; tax_bp: string; po_tolerance_bp: string; over_receipt_bp: string; invoice_price_tolerance_bp: string; invoice_qty_tolerance_bp: string };
type BudgetForm = { department: string; period: string; amount: string; lock_version: number | null };

const PERCENT_FIELDS = ['tax_bp', 'po_tolerance_bp', 'over_receipt_bp', 'invoice_price_tolerance_bp', 'invoice_qty_tolerance_bp'] as const;
const reload = ['overview'];

const toForm = (s: Settings): PolicyForm => ({
    budget_policy: s.budget_policy, tax_bp: bpToInput(s.tax_bp), po_tolerance_bp: bpToInput(s.po_tolerance_bp), over_receipt_bp: bpToInput(s.over_receipt_bp),
    invoice_price_tolerance_bp: bpToInput(s.invoice_price_tolerance_bp), invoice_qty_tolerance_bp: bpToInput(s.invoice_qty_tolerance_bp),
});

export default function PurchasingSettingsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const policyAction = useServerAction();
    const budgetAction = useServerAction();
    const [policy, setPolicy] = useState<PolicyForm>(() => toForm(overview.settings));
    const [badPercent, setBadPercent] = useState<string[]>([]);
    const [saved, setSaved] = useState(false);
    const [budget, setBudget] = useState<BudgetForm | null>(null);
    const [badAmount, setBadAmount] = useState(false);
    const manage = overview.may.manage;
    const dept = (d: string) => t(`inv.dept.${d}` as MessageKey);

    async function savePolicy() {
        const parsed = PERCENT_FIELDS.map((f) => [f, parsePercentToBp(policy[f])] as const);
        const bad = parsed.filter(([, bp]) => bp === null).map(([f]) => f);

        setBadPercent(bad);
        setSaved(false);
        if (bad.length > 0) return;
        const bp = Object.fromEntries(parsed) as Record<(typeof PERCENT_FIELDS)[number], number>;
        const done = await policyAction.run('/inventory/purchasing-settings', {
            body: {
                budget_policy: policy.budget_policy, tax_bp: bp.tax_bp, po_tolerance_bp: bp.po_tolerance_bp, over_receipt_bp: bp.over_receipt_bp,
                invoice_price_tolerance_bp: bp.invoice_price_tolerance_bp, invoice_qty_tolerance_bp: bp.invoice_qty_tolerance_bp, lock_version: overview.settings.lock_version,
            },
            reload,
        });
        if (done !== null) setSaved(true);
    }

    function openBudget(existing: Budget | null) {
        budgetAction.clear();
        setBadAmount(false);
        setBudget(existing === null
            ? { department: overview.departments[0] ?? '', period: '', amount: '', lock_version: null }
            : { department: existing.department, period: existing.period, amount: minorToInput(existing.amount_minor, overview.currency), lock_version: existing.lock_version });
    }

    async function saveBudget() {
        if (budget === null) return;
        const minor = parseMajorToMinor(budget.amount, overview.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await budgetAction.run('/inventory/purchasing-settings/budgets', {
            body: { department: budget.department, period: budget.period, amount_minor: minor, lock_version: budget.lock_version },
            reload,
        });
        if (done !== null) setBudget(null);
    }

    const percentField = (name: (typeof PERCENT_FIELDS)[number], label: string, hint: string) => (
        // The server requires all five, so the star is forced here: the generator only reads field names written literally.
        <FormField error={badPercent.includes(name) ? t('inv.po.invalidPercent') : policyAction.fieldError(name)} field={name} hint={hint} label={label} required>
            <Input disabled={!manage} inputMode="decimal" onChange={(e) => setPolicy({ ...policy, [name]: e.target.value })} value={policy[name]} />
        </FormField>
    );

    const columns: DataGridColumn<Budget>[] = [
        { id: 'department', label: t('inv.col.department'), value: (b) => b.department, filter: 'select', filterLabel: dept, rowHeader: true, cell: (b) => dept(b.department) },
        { id: 'period', label: t('inv.pset.period'), value: (b) => b.period, filter: 'select' },
        { id: 'amount', label: t('inv.pset.amount'), align: 'right', value: (b) => b.amount_minor, cell: (b) => format.money(b.amount_minor, overview.currency) },
        ...(manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (b: Budget) => <Button onClick={() => openBudget(b)} size="sm" type="button" variant="outline">{t('inv.pset.change')}</Button> }] : []),
    ];

    return (
        <InventoryShell actions={manage ? <Button onClick={() => openBudget(null)} type="button">{t('inv.pset.setBudget')}</Button> : undefined} description={t('inv.pset.description')} title={t('inv.pset.title')} wide>
            {!manage ? <Alert title={t('inv.pset.readOnly')} tone="info" /> : null}

            <section aria-labelledby="pset-policy-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="pset-policy-h">{t('inv.pset.policyHeading')}</h2>
                {policyAction.error !== null ? <ErrorState {...errorCopy} error={policyAction.error} onRefresh={() => window.location.reload()} /> : null}
                {saved ? <Alert title={t('inv.pset.saved')} tone="success" /> : null}
                <div className="grid gap-4 border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="purchasing-policy">
                    <div className="sm:col-span-2 lg:col-span-3">
                        <FormField error={policyAction.fieldError('budget_policy')} field="budget_policy" hint={t(`inv.pset.policy.${policy.budget_policy}.hint` as MessageKey)} label={t('inv.pset.policy')}>
                            <Select disabled={!manage} onChange={(e) => setPolicy({ ...policy, budget_policy: e.target.value })} searchable={false} value={policy.budget_policy}>
                                {overview.policies.map((p) => <option key={p} value={p}>{t(`inv.pset.policy.${p}` as MessageKey)}</option>)}
                            </Select>
                        </FormField>
                    </div>
                    {percentField('tax_bp', t('inv.pset.tax'), t('inv.pset.taxHint'))}
                    {percentField('po_tolerance_bp', t('inv.pset.poTolerance'), t('inv.pset.poToleranceHint'))}
                    {percentField('over_receipt_bp', t('inv.pset.overReceipt'), t('inv.pset.overReceiptHint'))}
                    {percentField('invoice_price_tolerance_bp', t('inv.pset.invoicePrice'), t('inv.pset.invoicePriceHint'))}
                    {percentField('invoice_qty_tolerance_bp', t('inv.pset.invoiceQty'), t('inv.pset.invoiceQtyHint'))}
                    {manage ? <div className="flex items-end sm:col-span-2 lg:col-span-3"><Button loading={policyAction.busy} onClick={() => void savePolicy()} type="button">{t('inv.action.save')}</Button></div> : null}
                </div>
            </section>

            <section aria-labelledby="pset-budgets-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="pset-budgets-h">{t('inv.pset.budgets')}</h2>
                <p className="text-sm text-muted-foreground">{t('inv.pset.budgetsHint')}</p>
                <DataGrid caption={t('inv.pset.budgets')} columns={columns} empty={<EmptyState title={t('inv.pset.noBudgets')} />} getRowId={(b) => b.id} id="inv.budgets" rows={overview.budgets} testId="budgets" />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={budgetAction.busy} onClick={() => setBudget(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={budgetAction.busy} onClick={() => void saveBudget()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setBudget(null)}
                open={budget !== null}
                title={budget?.lock_version === null ? t('inv.pset.setBudget') : t('inv.pset.change')}
            >
                {budget !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {budgetAction.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={budgetAction.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={budgetAction.fieldError('department')} field="department" label={t('inv.col.department')}>
                            <Select disabled={budget.lock_version !== null} onChange={(e) => setBudget({ ...budget, department: e.target.value })} value={budget.department}>
                                {overview.departments.map((d) => <option key={d} value={d}>{dept(d)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={budgetAction.fieldError('period')} field="period" hint={t('inv.pset.periodHint')} label={t('inv.pset.period')}>
                            <Input disabled={budget.lock_version !== null} onChange={(e) => setBudget({ ...budget, period: e.target.value })} placeholder="YYYY-MM" type="month" value={budget.period} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={badAmount ? t('fo.folio.invalidAmount') : budgetAction.fieldError('amount_minor')} field="amount_minor" label={t('inv.pset.amountIn', { currency: overview.currency })}>
                                <Input inputMode="decimal" onChange={(e) => setBudget({ ...budget, amount: e.target.value })} value={budget.amount} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
