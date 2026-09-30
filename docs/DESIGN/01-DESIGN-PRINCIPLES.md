# Design Principles

1. Operational clarity before decoration.
2. One primary action per task context.
3. Make system state visible: business date, property, outlet, shift, sync state, last refresh.
4. Dangerous and irreversible-looking actions explain their consequence and true backend behavior (void/reversal, not delete).
5. Reduce field entry using source-of-truth data, defaults, barcode/QR, and remembered non-sensitive preferences.
6. Surface conflicts instead of silently overwriting.
7. Dense desktop tables; touch-friendly mobile task views.
8. No generic "AI dashboard" pattern of excessive rounded cards, arbitrary gradients, decorative charts, and duplicated metrics.
