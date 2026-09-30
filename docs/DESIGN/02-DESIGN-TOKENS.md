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
