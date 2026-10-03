import { cn } from '@/shared/lib/utils';

/** The artwork of the product: the people of the hotel, the states a screen can be in, and the objects of a stay. Files live in `assets/illustrations`. */
const NAMES = [
    'armchair', 'bell', 'calendar', 'card', 'chart', 'checklist', 'coffee', 'do-not-disturb', 'done', 'empty', 'error', 'folder', 'forbidden', 'guest-service', 'help', 'hotel',
    'housekeeping', 'info', 'key-tag', 'keycard', 'lamp', 'loading', 'location', 'luggage', 'maintenance', 'mobile-tasks', 'notification', 'offline', 'plant', 'plant-small',
    'reception', 'room', 'session-expired', 'settings', 'success', 'support', 'upload', 'warning', 'wifi',
] as const;

export type IllustrationName = (typeof NAMES)[number];

const FILES = import.meta.glob<string>('../../assets/illustrations/*.webp', { eager: true, import: 'default', query: '?url' });

const source = (name: IllustrationName): string => FILES[`../../assets/illustrations/${name}.webp`] ?? '';

type IllustrationProps = {
    name: IllustrationName;
    /** Width and spacing; the height follows the picture. */
    className?: string;
};

/** A decorative picture: it carries no information the text next to it does not, so it is hidden from assistive technology. */
function Illustration({ className, name }: IllustrationProps) {
    return <img alt="" aria-hidden="true" className={cn('h-auto max-w-full select-none', className)} decoding="async" draggable={false} loading="lazy" src={source(name)} />;
}

export { Illustration };
