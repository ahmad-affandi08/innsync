import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType } from 'react';

import { createAppQueryClient } from '@/shared/api/query-client';
import { ensureMessages, localeFromProps } from '@/shared/i18n/i18n';
import { OfflineProvider } from '@/shared/offline/offline-provider';

const appName = import.meta.env.VITE_APP_NAME ?? 'InnSYnc';
const pages = import.meta.glob<{ default: ComponentType }>(
    './modules/**/*.tsx',
);
const queryClient = createAppQueryClient();

void createInertiaApp({
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
        component.layout ??= OfflineProvider;

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
