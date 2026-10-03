# States and Feedback

Standard states: loading, skeleton, empty, filtered-empty, stale, offline, syncing, sync-failed, conflict, success, warning, provider-unknown, forbidden, session-expired, server-error.

Payment `Unknown` has a dedicated visual state and cannot be styled as success. Offline pending transactions are visibly pending until acknowledged by server. Approved/Rejected/Pending approvals are explicit.

## Illustrations

The product's artwork lives in `resources/js/assets/illustrations` (transparent WebP, cut from the supplied sheet) and is drawn only through `components/ui/illustration.tsx`, by name. A picture is decoration: it is hidden from assistive technology and never carries a meaning the text next to it does not.

- Empty states show the empty folder by default (`EmptyState`, `illustration` prop to change or `null` for none).
- Error states show a picture by failure kind (`ErrorState`): forbidden, offline, session expired or rate limited, conflict (warning), anything else (error).
- A screen the person may not open, or that is gone, is the page `foundation/pages/error` with the lock, the folder or a warning, the server's own message and the way out (back, home), in the application frame; programs keep the JSON envelope.
- Sign-in shows the reception, and the home page the guest service picture. Other pictures (people of the hotel, objects of a stay) are for onboarding, help and success moments; pick by name, do not add new files without the same transparent treatment.
