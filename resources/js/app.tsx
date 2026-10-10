import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType, ReactNode } from 'react';

import { PoweredBy } from '@/components/layout/powered-by';
import { OfflineSnapshotNotice } from '@/components/layout/offline-snapshot-notice';
import { PrintLetterhead } from '@/components/layout/print-letterhead';
import { createAppQueryClient } from '@/shared/api/query-client';
import { ensureMessages, localeFromProps } from '@/shared/i18n/i18n';
import { OfflineProvider } from '@/shared/offline/offline-provider';
import { fillOfflineShell, startPageMemory } from '@/shared/offline/page-memory';
import type { InertiaPageLike } from '@/shared/offline/page-snapshot';
import { registerServiceWorker } from '@/shared/pwa/register';

const appName = import.meta.env.VITE_APP_NAME ?? 'InnSYnc';
const pages = import.meta.glob<{ default: ComponentType }>(
    './modules/**/*.tsx',
);
const queryClient = createAppQueryClient();

registerServiceWorker(navigator, window, import.meta.env.DEV);

/** Wraps every page: the offline queue, and the property's logo at the top of what is printed. */
function AppLayout({ children }: { children: ReactNode }) {
    return (
        <OfflineProvider>
            <PrintLetterhead />
            <OfflineSnapshotNotice />
            {children}
            <PoweredBy print />
        </OfflineProvider>
    );
}

/** The page sent with this document, if any (the offline shell has none until the device fills it). */
function firstPage(): InertiaPageLike | null {
    try {
        const text = document.querySelector('script[data-page="app"][type="application/json"]')?.textContent;

        return text ? (JSON.parse(text) as InertiaPageLike) : null;
    } catch {
        return null;
    }
}

async function start(): Promise<void> {
    const el = document.getElementById('app');

    // Opened from nothing (service worker sent the shell because the network is down): show the page kept on this device, or say there is none.
    if (el?.dataset.offlineShell !== undefined && !(await fillOfflineShell(el))) {
        return;
    }

    await createInertiaApp({
        title: (title) => (title ? `${title} — ${appName}` : appName),
        resolve: async (name, page) => {
            // Dictionaries are loaded before the page renders so no raw keys flash.
            const locale = localeFromProps(page?.props.locale);

            await ensureMessages(locale);
            document.documentElement.lang = locale;

            const component = (
                await resolvePageComponent(`./modules/${name}.tsx`, pages)
            ).default as ResolvedComponent;

            // The same layout component on every page keeps the offline queue mounted across navigation.
            component.layout ??= AppLayout;

            return component;
        },
        strictMode: true,
        withApp: (app) => (
            <QueryClientProvider client={queryClient}>{app}</QueryClientProvider>
        ),
        progress: {
            color: 'var(--primary)',
        },
    });
    startPageMemory(firstPage());
}

void start();
