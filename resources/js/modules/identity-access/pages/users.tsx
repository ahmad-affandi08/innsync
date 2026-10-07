import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Assignment = { id: string; role_id: string; role_name: string; scope_type: string; scope_id: string; scope_name: string; is_active: boolean };
type Person = { id: string; name: string; email: string; is_active: boolean; must_change_password: boolean; mfa: boolean; last_login_at: string | null; assignments: Assignment[] };
type Role = { id: string; name: string; is_active: boolean };
type Outlet = { id: string; name: string };

type Dialogue =
    | { kind: 'create'; name: string; email: string; roleId: string; scopeType: string; outletId: string; reason: string }
    | { kind: 'assign'; person: Person; roleId: string; scopeType: string; outletId: string; reason: string }
    | { kind: 'revoke'; person: Person; assignment: Assignment; reason: string }
    | { kind: 'active'; person: Person; active: boolean; reason: string }
    | { kind: 'reset'; person: Person; reason: string };

export default function UsersPage({ people, roles, outlets }: { people: Person[]; roles: Role[]; outlets: Outlet[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [dialogue, setDialogue] = useState<Dialogue | null>(null);
    const [handOver, setHandOver] = useState<{ email: string; password: string } | null>(null);
    const [copied, setCopied] = useState(false);
    const activeRoles = roles.filter((r) => r.is_active);

    const scopeLabel = (a: Assignment) => (a.scope_type === 'outlet' ? t('acc.scope.outlet', { name: a.scope_name }) : t('acc.scope.property'));

    function open(next: Dialogue) {
        action.clear();
        setDialogue(next);
    }

    async function submit() {
        if (dialogue === null) return;
        const reload = ['people'];
        const scope = (d: { scopeType: string; outletId: string }) => ({ scope_type: d.scopeType, scope_id: d.scopeType === 'outlet' ? d.outletId : null });
        let done: unknown = null;

        if (dialogue.kind === 'create') {
            const result = await action.run<{ temporary_password: string; email: string }>('/access/users', {
                body: { name: dialogue.name, email: dialogue.email, role_id: dialogue.roleId, reason: dialogue.reason, ...scope(dialogue) },
                reload,
            });
            if (result !== null) {
                setCopied(false);
                setHandOver({ email: result.email, password: result.temporary_password });
            }
            done = result;
        } else if (dialogue.kind === 'assign') {
            done = await action.run(`/access/users/${dialogue.person.id}/roles`, { body: { role_id: dialogue.roleId, reason: dialogue.reason, ...scope(dialogue) }, reload });
        } else if (dialogue.kind === 'revoke') {
            done = await action.run(`/access/assignments/${dialogue.assignment.id}/revoke`, { body: { reason: dialogue.reason }, reload });
        } else if (dialogue.kind === 'active') {
            done = await action.run(`/access/users/${dialogue.person.id}/active`, { body: { active: dialogue.active, reason: dialogue.reason }, reload });
        } else {
            const result = await action.run<{ temporary_password: string }>(`/access/users/${dialogue.person.id}/reset-password`, { body: { reason: dialogue.reason }, reload });
            if (result !== null) {
                setCopied(false);
                setHandOver({ email: dialogue.person.email, password: result.temporary_password });
            }
            done = result;
        }

        if (done !== null) setDialogue(null);
    }

    async function copy(text: string) {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    }

    const title = (() => {
        if (dialogue === null) return '';
        switch (dialogue.kind) {
            case 'create': return t('acc.create.title');
            case 'assign': return t('acc.assign.title', { name: dialogue.person.name });
            case 'revoke': return t('acc.revoke.title', { role: dialogue.assignment.role_name, name: dialogue.person.name });
            case 'active': return t(dialogue.active ? 'acc.activate.title' : 'acc.deactivate.title', { name: dialogue.person.name });
            default: return t('acc.reset.title', { name: dialogue.person.name });
        }
    })();

    const submitLabel = dialogue === null ? '' : {
        create: t('acc.create.submit'),
        assign: t('acc.assign.submit'),
        revoke: t('acc.revoke.submit'),
        active: dialogue.kind === 'active' && dialogue.active ? t('acc.users.activate') : t('acc.users.deactivate'),
        reset: t('acc.users.resetPassword'),
    }[dialogue.kind];

    const roleFields = dialogue !== null && (dialogue.kind === 'create' || dialogue.kind === 'assign') ? (
        <>
            <FormField error={action.fieldError('role_id')} field="role_id" label={t('acc.field.role')}>
                <Select onChange={(e) => setDialogue({ ...dialogue, roleId: e.target.value })} searchable={false} value={dialogue.roleId}>
                    <option value="">—</option>
                    {activeRoles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                </Select>
            </FormField>
            <FormField error={action.fieldError('scope_type')} field="scope_type" label={t('acc.field.scope')}>
                <Select onChange={(e) => setDialogue({ ...dialogue, scopeType: e.target.value })} searchable={false} value={dialogue.scopeType}>
                    <option value="property">{t('acc.scope.property')}</option>
                    {outlets.length > 0 ? <option value="outlet">{t('acc.scope.outlet', { name: '…' })}</option> : null}
                </Select>
            </FormField>
            {dialogue.scopeType === 'outlet' ? (
                <FormField error={action.fieldError('scope_id')} field="scope_id" label={t('acc.field.outlet')}>
                    <Select onChange={(e) => setDialogue({ ...dialogue, outletId: e.target.value })} searchable={false} value={dialogue.outletId}>
                        <option value="">—</option>
                        {outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </Select>
                </FormField>
            ) : null}
        </>
    ) : null;

    return (
        <>
            <PropertyShell
                actions={<>
                    <Button asChild variant="outline"><a href="/access/roles">{t('acc.users.rolesLink')}</a></Button>
                    <Button
                        disabled={activeRoles.length === 0}
                        onClick={() => open({ kind: 'create', name: '', email: '', roleId: '', scopeType: 'property', outletId: '', reason: '' })}
                        type="button"
                    >{t('acc.users.add')}</Button>
                </>}
                description={t('acc.users.description')}
                title={t('acc.users.title')}
            >
                {dialogue === null && action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                {activeRoles.length === 0 ? <Alert title={t('acc.users.noRolesAvailable')} tone="warning" /> : null}
                {people.length === 0 ? <p className="text-sm text-muted-foreground">{t('acc.users.empty')}</p> : (
                    <ul className="divide-y divide-border border-y border-border">
                        {people.map((p) => {
                            const live = p.assignments.filter((a) => a.is_active);

                            return (
                                <li className="flex flex-col gap-3 py-4" data-testid={`person-${p.id}`} key={p.id}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold">{p.name}</p>
                                            <p className="truncate text-sm text-muted-foreground">{p.email}</p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {p.last_login_at === null ? t('acc.users.neverLogin') : t('acc.users.lastLogin', { when: format.instant(p.last_login_at) })}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge label={t(p.is_active ? 'acc.users.active' : 'acc.users.inactive')} tone={p.is_active ? 'success' : 'neutral'} />
                                            {p.must_change_password ? <StatusBadge label={t('acc.users.mustChange')} tone="warning" /> : null}
                                            {p.mfa ? <StatusBadge label={t('acc.users.mfa')} tone="info" /> : null}
                                        </div>
                                    </div>
                                    {live.length === 0 ? <p className="text-sm text-muted-foreground">{t('acc.users.noRoles')}</p> : (
                                        <ul className="flex flex-wrap gap-2">
                                            {live.map((a) => (
                                                <li className="flex items-center gap-2 border border-border px-2.5 py-1.5 text-sm" key={a.id}>
                                                    <span>{a.role_name} · {scopeLabel(a)}</span>
                                                    <Button onClick={() => open({ kind: 'revoke', person: p, assignment: a, reason: '' })} size="sm" type="button" variant="outline">{t('acc.users.revoke')}</Button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        <Button disabled={activeRoles.length === 0} onClick={() => open({ kind: 'assign', person: p, roleId: '', scopeType: 'property', outletId: '', reason: '' })} size="sm" type="button" variant="outline">{t('acc.users.giveRole')}</Button>
                                        <Button onClick={() => open({ kind: 'reset', person: p, reason: '' })} size="sm" type="button" variant="outline">{t('acc.users.resetPassword')}</Button>
                                        <Button onClick={() => open({ kind: 'active', person: p, active: !p.is_active, reason: '' })} size="sm" type="button" variant="outline">{t(p.is_active ? 'acc.users.deactivate' : 'acc.users.activate')}</Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </PropertyShell>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setDialogue(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void submit()} type="button" variant={dialogue?.kind === 'revoke' || (dialogue?.kind === 'active' && !dialogue.active) ? 'destructive' : 'default'}>{submitLabel}</Button>
                </>}
                onClose={() => setDialogue(null)}
                open={dialogue !== null}
                title={title}
            >
                {dialogue !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        {dialogue.kind === 'create' ? (
                            <>
                                <p className="text-sm text-muted-foreground">{t('acc.create.hint')}</p>
                                <FormField error={action.fieldError('name')} field="name" label={t('acc.field.name')}>
                                    <Input autoComplete="off" maxLength={255} onChange={(e) => setDialogue({ ...dialogue, name: e.target.value })} value={dialogue.name} />
                                </FormField>
                                <FormField error={action.fieldError('email')} field="email" label={t('acc.field.email')}>
                                    <Input autoComplete="off" inputMode="email" maxLength={255} onChange={(e) => setDialogue({ ...dialogue, email: e.target.value })} type="email" value={dialogue.email} />
                                </FormField>
                            </>
                        ) : null}
                        {dialogue.kind === 'active' && !dialogue.active ? <p className="text-sm text-muted-foreground">{t('acc.deactivate.body')}</p> : null}
                        {dialogue.kind === 'reset' ? <p className="text-sm text-muted-foreground">{t('acc.reset.body')}</p> : null}
                        {roleFields}
                        <FormField error={action.fieldError('reason')} field="reason" hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                            <Input maxLength={500} onChange={(e) => setDialogue({ ...dialogue, reason: e.target.value })} value={dialogue.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<Button onClick={() => setHandOver(null)} type="button">{t('acc.temp.done')}</Button>}
                onClose={() => setHandOver(null)}
                open={handOver !== null}
                title={t('acc.temp.title')}
            >
                {handOver !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('acc.temp.body', { email: handOver.email })}</p>
                        <code className="block break-all border border-border bg-muted px-3 py-2 font-mono text-base" data-testid="temporary-password">{handOver.password}</code>
                        <div><Button onClick={() => void copy(handOver.password)} size="sm" type="button" variant="outline">{copied ? t('acc.temp.copied') : t('acc.temp.copy')}</Button></div>
                    </div>
                )}
            </Dialog>
        </>
    );
}
