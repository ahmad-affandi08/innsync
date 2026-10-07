import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Role = { id: string; name: string; requires_mfa: boolean; is_active: boolean; permissions: string[] };
type Draft = { id: string | null; name: string; mfa: boolean; permissions: string[]; reason: string };
type Toggle = { role: Role; active: boolean; reason: string };

const SYSTEM_ROLE = 'Administrator';

/** Groups the dotted permission codes by their first part (`hr.employee.manage` is under `hr`). */
function groupOf(code: string): string {
    return code.split('.')[0] ?? code;
}

export default function RolesPage({ roles, permissions }: { roles: Role[]; permissions: string[] }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [draft, setDraft] = useState<Draft | null>(null);
    const [toggle, setToggle] = useState<Toggle | null>(null);
    const [filter, setFilter] = useState('');
    const held = useMemo(() => new Set(permissions), [permissions]);

    // What the editor lists: the permissions the person holds, and any the role already has that they do not (shown locked).
    const listed = useMemo(() => {
        const all = new Set([...permissions, ...(draft?.permissions ?? [])]);
        const needle = filter.trim().toLowerCase();

        return [...all].filter((c) => needle === '' || c.toLowerCase().includes(needle)).sort();
    }, [permissions, draft, filter]);

    const groups = useMemo(() => {
        const out = new Map<string, string[]>();
        for (const code of listed) out.set(groupOf(code), [...(out.get(groupOf(code)) ?? []), code]);

        return [...out.entries()];
    }, [listed]);

    function openDraft(next: Draft) {
        action.clear();
        setFilter('');
        setDraft(next);
    }

    async function save() {
        if (draft === null) return;
        const body = { name: draft.name, requires_mfa: draft.mfa, permissions: draft.permissions, reason: draft.reason };
        const done = await action.run(draft.id === null ? '/access/roles' : `/access/roles/${draft.id}`, { body, reload: ['roles'] });
        if (done !== null) setDraft(null);
    }

    async function flip() {
        if (toggle === null) return;
        const done = await action.run(`/access/roles/${toggle.role.id}/active`, { body: { active: toggle.active, reason: toggle.reason }, reload: ['roles'] });
        if (done !== null) setToggle(null);
    }

    function setPermission(code: string, on: boolean) {
        if (draft === null) return;
        setDraft({ ...draft, permissions: on ? [...new Set([...draft.permissions, code])] : draft.permissions.filter((c) => c !== code) });
    }

    function setAllShown(on: boolean) {
        if (draft === null) return;
        const shown = listed.filter((c) => held.has(c));
        const rest = draft.permissions.filter((c) => !shown.includes(c));
        setDraft({ ...draft, permissions: on ? [...rest, ...shown] : rest });
    }

    return (
        <>
            <PropertyShell
                actions={<>
                    <Button asChild variant="outline"><a href="/access/users">{t('acc.nav.users')}</a></Button>
                    <Button onClick={() => openDraft({ id: null, name: '', mfa: false, permissions: [], reason: '' })} type="button">{t('acc.roles.add')}</Button>
                </>}
                description={t('acc.roles.description')}
                title={t('acc.roles.title')}
            >
                {draft === null && toggle === null && action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                {roles.length === 0 ? <p className="text-sm text-muted-foreground">{t('acc.roles.empty')}</p> : (
                    <ul className="divide-y divide-border border-y border-border">
                        {roles.map((r) => (
                            <li className="flex flex-wrap items-center justify-between gap-3 py-3" data-testid={`role-${r.id}`} key={r.id}>
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold">{r.name}</p>
                                    <p className="text-xs text-muted-foreground">{t('acc.roles.permCount', { count: r.permissions.length })}</p>
                                    <div className="mt-1 flex flex-wrap gap-2">
                                        {!r.is_active ? <StatusBadge label={t('acc.roles.inactive')} tone="neutral" /> : null}
                                        {r.requires_mfa ? <StatusBadge label={t('acc.roles.mfa')} tone="info" /> : null}
                                    </div>
                                </div>
                                {r.name === SYSTEM_ROLE ? <p className="text-xs text-muted-foreground">{t('acc.roles.system')}</p> : (
                                    <div className="flex flex-wrap gap-2">
                                        <Button onClick={() => openDraft({ id: r.id, name: r.name, mfa: r.requires_mfa, permissions: r.permissions, reason: '' })} size="sm" type="button" variant="outline">{t('acc.roles.edit')}</Button>
                                        <Button onClick={() => { action.clear(); setToggle({ role: r, active: !r.is_active, reason: '' }); }} size="sm" type="button" variant="outline">{t(r.is_active ? 'acc.roles.deactivate' : 'acc.roles.activate')}</Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </PropertyShell>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setDraft(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('property.action.save')}</Button>
                </>}
                onClose={() => setDraft(null)}
                open={draft !== null}
                title={draft === null ? '' : draft.id === null ? t('acc.roles.createTitle') : t('acc.roles.editTitle', { name: draft.name })}
            >
                {draft !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('name')} field="name" label={t('acc.roles.field.name')}>
                            <Input maxLength={100} onChange={(e) => setDraft({ ...draft, name: e.target.value })} value={draft.name} />
                        </FormField>
                        <div className="flex items-start gap-2">
                            <Checkbox checked={draft.mfa} id="role-mfa" onCheckedChange={(v) => setDraft({ ...draft, mfa: v === true })} />
                            <Label htmlFor="role-mfa">{t('acc.roles.field.mfa')}</Label>
                        </div>
                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-medium">{t('acc.roles.field.permissions')} <span className="text-xs text-muted-foreground">({t('acc.roles.selected', { count: draft.permissions.length })})</span></p>
                                <div className="flex gap-2">
                                    <Button onClick={() => setAllShown(true)} size="sm" type="button" variant="outline">+</Button>
                                    <Button onClick={() => setAllShown(false)} size="sm" type="button" variant="outline">−</Button>
                                </div>
                            </div>
                            <Input aria-label={t('acc.roles.filter')} onChange={(e) => setFilter(e.target.value)} placeholder={t('acc.roles.filter')} value={filter} />
                            <div className="max-h-72 overflow-y-auto border border-border p-2">
                                {groups.map(([group, codes]) => (
                                    <fieldset className="mb-3" key={group}>
                                        <legend className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group}</legend>
                                        {codes.map((code) => (
                                            <div className="flex items-start gap-2 py-1" key={code}>
                                                <Checkbox
                                                    checked={draft.permissions.includes(code)}
                                                    disabled={!held.has(code)}
                                                    id={`perm-${code}`}
                                                    onCheckedChange={(v) => setPermission(code, v === true)}
                                                />
                                                <Label className="break-all text-sm font-normal" htmlFor={`perm-${code}`} title={held.has(code) ? undefined : t('acc.roles.locked')}>{code}</Label>
                                            </div>
                                        ))}
                                    </fieldset>
                                ))}
                            </div>
                        </div>
                        <FormField error={action.fieldError('reason')} field="reason" hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                            <Input maxLength={500} onChange={(e) => setDraft({ ...draft, reason: e.target.value })} value={draft.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setToggle(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void flip()} type="button" variant={toggle?.active === false ? 'destructive' : 'default'}>{toggle?.active ? t('acc.roles.activate') : t('acc.roles.deactivate')}</Button>
                </>}
                onClose={() => setToggle(null)}
                open={toggle !== null}
                title={toggle === null ? '' : t(toggle.active ? 'acc.roles.activateTitle' : 'acc.roles.deactivateTitle', { name: toggle.role.name })}
            >
                {toggle !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('reason')} field="reason" hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                            <Input maxLength={500} onChange={(e) => setToggle({ ...toggle, reason: e.target.value })} value={toggle.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </>
    );
}
