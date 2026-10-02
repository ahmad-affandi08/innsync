# Design Tokens

Tailwind 4 consumes semantic CSS variables. Feature code must use semantic names rather than hard-coded brand values.

Required token groups:

- surfaces: `background`, `surface`, `surface-muted`, `overlay`
- text: `foreground`, `muted-foreground`, `inverse`
- border/input/ring
- action: `primary`, `secondary`, `destructive`
- state: `success`, `warning`, `danger`, `info`
- room dimensions: occupancy / housekeeping / sellability / service-flag tokens
- chart series tokens with accessible contrast
- spacing/radius/shadow scale

Do not encode business meaning only with color; pair icon/text/status label.

## Brand (2026-10-02)

The palette comes from the InnSYnc logo: a deep navy (`#16233B`, primary actions) and an orange (`#F97316`, the `brand` token). Orange marks what is active (the current menu item, focus, hover) and never carries text on its own: orange text uses the darker `accent` token and filled orange buttons use `accent` with white text, both above 4.5:1. Filled primary actions stay navy. The contrast of the pairs that matter (body text, muted text, primary and accent buttons, sidebar text, accent links) is checked by the browser verification of each release.


## Flat style (2026-10-02)

The interface is flat and clean: **no border radius and no shadow anywhere**. The tokens `--radius-*` and `--shadow-*` are set to `0` and `none`, so a stray `rounded-*` or `shadow-*` class changes nothing; new code does not use them. Surfaces are separated by 1px borders and a light grey (`surface-muted`), not by elevation. The sidebar is white with a border, the active item is marked by an orange left border. Components are the shadcn/ui set on Radix (`resources/js/components/ui/`: card, tabs, table, dialog, alert-dialog, sheet, dropdown-menu, select-menu, popover, tooltip, hover-card, checkbox, radio-group, switch, accordion, collapsible, progress, avatar, breadcrumb, scroll-area, separator, toggle), owned in the repository and restyled to these tokens. The browser verification fails a page that renders any radius or shadow.

Table headers are **navy with bold white text** (`TableHeader`: `bg-primary`, 15.7:1). Badges (`Badge`, `StatusBadge`) are **solid, opaque** status colors with white bold text (neutral 6.0:1, success 7.6, warning 5.7, danger 6.4, info 7.8); no transparency. `pending` keeps a white badge with an info border and `unknown` a dashed warning border, so an unconfirmed state never looks settled.
