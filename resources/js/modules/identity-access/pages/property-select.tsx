import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { AuthShell } from '@/modules/identity-access/components/auth-shell';

type PropertyOption = { id: string; name: string };
type PropertySelectProps = { properties: PropertyOption[] };

export default function PropertySelectPage({ properties }: PropertySelectProps) {
    const form = useForm({ property_id: properties[0]?.id ?? '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/properties/select');
    }

    return (
        <>
            <Head title="Select property" />
            <AuthShell title="Select property" description="Your permissions are evaluated within the selected property.">
                {properties.length === 0 ? (
                    <p className="border border-warning bg-surface-muted p-4 text-sm">No active property assignment is available. Contact an administrator.</p>
                ) : (
                    <form className="space-y-5" onSubmit={submit}>
                        <fieldset className="space-y-2">
                            <legend className="mb-2 text-sm font-medium">Available properties</legend>
                            {properties.map((property) => (
                                <label className="flex min-h-11 cursor-pointer items-center gap-3 border border-border px-3 py-2" key={property.id}>
                                    <input checked={form.data.property_id === property.id} name="property_id" onChange={() => form.setData('property_id', property.id)} type="radio" />
                                    <span className="text-sm font-medium">{property.name}</span>
                                </label>
                            ))}
                        </fieldset>
                        {form.errors.property_id && <p className="text-sm text-danger">{form.errors.property_id}</p>}
                        <Button className="w-full" disabled={form.processing} type="submit">Continue</Button>
                    </form>
                )}
            </AuthShell>
        </>
    );
}
