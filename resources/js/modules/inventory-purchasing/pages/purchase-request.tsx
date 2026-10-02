import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ApprovalProgress } from '@/modules/inventory-purchasing/components/approval-progress';
import { BudgetWarning } from '@/modules/inventory-purchasing/components/budget-note';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { RequestFields, requestLinesBody, requestToForm, type RequestFormState } from '@/modules/inventory-purchasing/components/request-form';
import { REQUEST_TONE, URGENCY_TONE, type ApprovalSummary, type ItemChoice, type Standing } from '@/modules/inventory-purchasing/lib/purchasing';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = { id: string; item_id: string; item_code: string; item_name: string; unit: string; qty_milli: number; est_unit_cost_minor: number; line_total_minor: number; note: string | null; po_id: string | null };
type PurchaseRequest = {
    id: string; number: string; department: string; urgency: string; reason: string; needed_by: string; status: string; total_minor: number; decision_note: string | null;
    requested_by_name: string | null; submitted_at: string | null; business_date: string; line_count: number; ordered_count: number; lock_version: number;
    currency: string; departments: string[]; urgencies: string[]; items: ItemChoice[]; lines: Line[];
    approval: ApprovalSummary | null; budget: Standing;
    may_edit: boolean; may_submit: boolean; may_release: boolean; may_cancel: boolean;
};

const reload = ['purchaseRequest'];

export default function PurchaseRequestPage({ purchaseRequest: pr }: { purchaseRequest: PurchaseRequest }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [edit, setEdit] = useState<RequestFormState | null>(null);
    const [badCosts, setBadCosts] = useState<string[]>([]);
    const [cancelling, setCancelling] = useState<string | null>(null);
    const [warning, setWarning] = useState<Standing | null>(null);
    const money = (minor: number) => format.money(minor, pr.currency);
    const qty = (milli: number) => formatMilli(milli, locale);
    const label = (s: string) => t(`inv.req.status.${s}` as MessageKey);
    const dept = (d: string) => t(`inv.dept.${d}` as MessageKey);

    function openEdit() {
        action.clear();
        setBadCosts([]);
        setEdit(requestToForm(pr, pr.currency));
    }

    async function save() {
        if (edit === null) return;
        const { lines, bad } = requestLinesBody(edit, pr.currency);

        setBadCosts(bad);
        if (bad.length > 0) return;
        const done = await action.run(`/inventory/requests/${pr.id}`, {
            body: { department: edit.department, urgency: edit.urgency, reason: edit.reason, needed_by: edit.needed_by, lines, lock_version: pr.lock_version },
            reload,
        });
        if (done !== null) setEdit(null);
    }

    async function submit() {
        action.clear();
        const done = await action.run<{ request: { budget_warning: Standing | null } }>(`/inventory/requests/${pr.id}/submit`, { body: { lock_version: pr.lock_version }, reload });
        if (done !== null) setWarning(done.request.budget_warning ?? null);
    }

    async function release() {
        action.clear();
        await action.run(`/inventory/requests/${pr.id}/release`, { reload });
    }

    async function cancel() {
        if (cancelling === null) return;
        const done = await action.run(`/inventory/requests/${pr.id}/cancel`, { body: { reason: cancelling, lock_version: pr.lock_version }, reload });
        if (done !== null) setCancelling(null);
    }

    const facts: [string, string][] = [
        [t('inv.col.department'), dept(pr.department)],
        [t('inv.req.neededBy'), format.date(pr.needed_by)],
        [t('inv.req.requestedBy'), pr.requested_by_name ?? '—'],
        [t('inv.req.submittedAt'), pr.submitted_at === null ? '—' : format.instant(pr.submitted_at)],
        [t('inv.req.reason'), pr.reason],
        [t('inv.req.total'), money(pr.total_minor)],
    ];
    const budget = pr.budget;

    return (
        <InventoryShell
            actions={<>
                <Button asChild variant="outline"><Link href="/inventory/requests">{t('inv.req.back')}</Link></Button>
                <Button onClick={() => window.print()} type="button" variant="outline">{t('inv.po.print')}</Button>
                {pr.may_edit ? <Button onClick={openEdit} type="button" variant="outline">{t('inv.req.edit')}</Button> : null}
                {pr.may_release ? <Button disabled={action.busy} onClick={() => void release()} type="button" variant="outline">{t('inv.req.release')}</Button> : null}
                {pr.may_cancel ? <Button onClick={() => { action.clear(); setCancelling(''); }} type="button" variant="outline">{t('inv.req.cancel')}</Button> : null}
                {pr.may_submit ? <Button loading={action.busy} onClick={() => void submit()} type="button">{t('inv.req.submit')}</Button> : null}
            </>}
            description={t('inv.req.detailDescription')}
            title={t('inv.req.detailTitle', { number: pr.number })}
            wide
        >
            {action.error !== null && edit === null && cancelling === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {warning !== null ? <BudgetWarning currency={pr.currency} standing={warning} /> : null}
            {pr.decision_note && (pr.status === 'rejected' || pr.status === 'cancelled') ? <Alert title={t(pr.status === 'rejected' ? 'inv.req.rejectedNote' : 'inv.req.cancelledNote')} tone={pr.status === 'rejected' ? 'danger' : 'info'}>{pr.decision_note}</Alert> : null}

            <section aria-labelledby="pr-details-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="pr-details-h">{t('inv.req.details')}</h2>
                    <div className="flex items-center gap-2">
                        <StatusBadge label={t(`inv.req.urgency.${pr.urgency}` as MessageKey)} tone={URGENCY_TONE[pr.urgency] ?? 'neutral'} />
                        <StatusBadge label={label(pr.status)} tone={REQUEST_TONE[pr.status] ?? 'neutral'} />
                    </div>
                </div>
                <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-3" data-testid="request-details">
                    {facts.map(([name, value]) => (
                        <div key={name}>
                            <dt className="text-xs text-muted-foreground">{name}</dt>
                            <dd className="break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section aria-labelledby="pr-lines-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="pr-lines-h">{t('inv.req.linesHeading')}</h2>
                {pr.lines.length === 0 ? <EmptyState title={t('inv.req.noLines')} /> : (
                    <div className="border border-border bg-surface">
                        <Table data-testid="request-lines">
                            <TableHeader>
                                <TableRow>
                                    <TableHead scope="col">{t('inv.col.item')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.opening.quantity')}</TableHead>
                                    <TableHead scope="col">{t('inv.col.unit')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.req.estCostShort')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.req.lineTotal')}</TableHead>
                                    <TableHead scope="col">{t('inv.col.note')}</TableHead>
                                    <TableHead scope="col">{t('inv.req.orderedOn')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pr.lines.map((l) => (
                                    <TableRow key={l.id}>
                                        <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                        <TableCell className="text-right">{qty(l.qty_milli)}</TableCell>
                                        <TableCell>{l.unit}</TableCell>
                                        <TableCell className="text-right">{money(l.est_unit_cost_minor)}</TableCell>
                                        <TableCell className="text-right">{money(l.line_total_minor)}</TableCell>
                                        <TableCell>{l.note ?? '—'}</TableCell>
                                        <TableCell>{l.po_id === null ? '—' : <Link className="underline" href={`/inventory/orders/${l.po_id}`}>{t('inv.req.viewOrder')}</Link>}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell className="font-semibold" colSpan={4}>{t('inv.req.total')}</TableCell>
                                    <TableCell className="text-right font-semibold" data-testid="request-total">{money(pr.total_minor)}</TableCell>
                                    <TableCell colSpan={2} />
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                )}
            </section>

            {pr.approval !== null ? (
                <section aria-labelledby="pr-approval-h" className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold" id="pr-approval-h">{t('inv.po.apr.heading')}</h2>
                    <ApprovalProgress approval={pr.approval} />
                </section>
            ) : null}

            <section aria-labelledby="pr-budget-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="pr-budget-h">{t('inv.req.budget')}</h2>
                {budget.budget_minor === null ? (
                    <p className="text-sm text-muted-foreground" data-testid="request-budget">{t('inv.req.noBudget', { department: dept(budget.department), period: budget.period })}</p>
                ) : (
                    <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-5" data-testid="request-budget">
                        <div><dt className="text-xs text-muted-foreground">{t('inv.req.budgetPeriod')}</dt><dd>{dept(budget.department)} · {budget.period}</dd></div>
                        <div><dt className="text-xs text-muted-foreground">{t('inv.pset.policy')}</dt><dd>{t(`inv.pset.policy.${budget.policy}` as MessageKey)}</dd></div>
                        <div><dt className="text-xs text-muted-foreground">{t('inv.po.bud.budget')}</dt><dd>{money(budget.budget_minor)}</dd></div>
                        <div><dt className="text-xs text-muted-foreground">{t('inv.po.bud.committed')}</dt><dd>{money(budget.committed_minor)}</dd></div>
                        <div><dt className="text-xs text-muted-foreground">{t('inv.po.bud.remaining')}</dt><dd className={budget.over ? 'font-semibold text-danger' : undefined}>{money(budget.remaining_minor ?? 0)}</dd></div>
                    </dl>
                )}
                {budget.over ? <Alert title={t('inv.req.overBudget')} tone="warning">{t('inv.req.overBudgetDetail', { policy: t(`inv.pset.policy.${budget.policy}` as MessageKey) })}</Alert> : null}
            </section>

            <Dialog
                className="w-[min(64rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setEdit(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setEdit(null)}
                open={edit !== null}
                title={t('inv.req.edit')}
            >
                {edit !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <RequestFields badCosts={badCosts} currency={pr.currency} departments={pr.departments} fieldError={action.fieldError} form={edit} items={pr.items} onChange={setEdit} urgencies={pr.urgencies} />
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setCancelling(null)} type="button" variant="outline">{t('inv.trf.back')}</Button>
                    <Button loading={action.busy} onClick={() => void cancel()} type="button">{t('inv.req.cancelConfirm')}</Button>
                </>}
                onClose={() => setCancelling(null)}
                open={cancelling !== null}
                title={t('inv.req.cancel')}
            >
                {cancelling !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('inv.req.cancelHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('inv.col.reason')}>
                            <Input maxLength={200} onChange={(e) => setCancelling(e.target.value)} value={cancelling} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
