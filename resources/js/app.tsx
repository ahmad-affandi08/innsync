import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType } from 'react';

const appName = import.meta.env.VITE_APP_NAME ?? 'InnSYnc';
const pages = import.meta.glob<{ default: ComponentType }>(
    './modules/**/*.tsx',
);
const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
            retry: 1,
            staleTime: 30_000,
        },
    },
});

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: async (name) =>
        (
            await resolvePageComponent(
                `./modules/${name}.tsx`,
                pages,
            )
        ).default,
    strictMode: true,
    withApp: (app) => (
        <QueryClientProvider client={queryClient}>{app}</QueryClientProvider>
    ),
    progress: {
        color: 'var(--primary)',
    },
});
