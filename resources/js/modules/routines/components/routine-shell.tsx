import type { ReactNode } from 'react';

import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import { KitchenShell } from '@/modules/kitchen/components/kitchen-shell';

type Props = { department: string; title: string; description: string; children: ReactNode };

/** The frame of the department the routines belong to: the kitchen or an outlet. */
export function RoutineShell({ children, department, description, title }: Props) {
    return department === 'kitchen'
        ? <KitchenShell description={description} title={title}>{children}</KitchenShell>
        : <FnbShell description={description} title={title} wide>{children}</FnbShell>;
}
