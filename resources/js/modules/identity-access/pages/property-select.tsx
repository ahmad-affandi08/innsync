import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type PropertyOption = { id: string; name: string };
type PropertySelectProps = { properties: PropertyOption[] };

export default function PropertySelectPage({ properties }: PropertySelectProps) {
    const { t } = useTranslation();
    const form = useForm({ property_id: properties[0]?.id ?? '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/properties/select');
    }

    return (
        <>
            <Head title={t('identity.property.title')} />
            <AuthShell title={t('identity.property.title')} description={t('identity.property.description')}>
                {properties.length === 0 ? (
                    <p className="border border-warning bg-surface-muted p-4 text-sm" role="status">{t('identity.property.none')}</p>
                ) : (
                    <form className="space-y-5" onSubmit={submit}>
                        <fieldset className="space-y-2">
                            <legend className="mb-2 text-sm font-medium">{t('identity.property.available')}</legend>
                            {properties.map((property) => (
                                <label className="flex min-h-11 cursor-pointer items-center gap-3 border border-border px-3 py-2" key={property.id}>
                                    <input checked={form.data.property_id === property.id} name="property_id" onChange={() => form.setData('property_id', property.id)} type="radio" />
                                    <span className="text-sm font-medium">{property.name}</span>
                                </label>
                            ))}
                        </fieldset>
                        {form.errors.property_id && <p className="text-sm text-danger" role="alert">{form.errors.property_id}</p>}
                        <Button className="w-full" loading={form.processing} type="submit">{t('common.action.continue')}</Button>
                    </form>
                )}
            </AuthShell>
        </>
    );
}
