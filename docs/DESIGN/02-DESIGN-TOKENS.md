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

The palette comes from the InnSYnc logo: a deep navy (`#16233B`, the sidebar and primary actions) and an orange (`#F97316`, the `brand` token). Orange marks what is active (the current menu item, focus, hover) and never carries text on its own: orange text uses the darker `accent` token and filled orange buttons use `accent` with white text, both above 4.5:1. Filled primary actions stay navy. The contrast of the pairs that matter (body text, muted text, primary and accent buttons, sidebar text, accent links) is checked by the browser verification of each release.

