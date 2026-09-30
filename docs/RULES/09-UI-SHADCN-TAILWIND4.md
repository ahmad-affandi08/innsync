# shadcn/ui + Tailwind 4 Rules

- Feature code consumes semantic shared components; do not copy/paste a different button/input/table implementation per module.
- Use CSS variable design tokens. Avoid raw ad-hoc hex colors in feature components.
- Preserve accessible labels, focus rings, keyboard interaction, and dialog semantics from shadcn/Radix primitives.
- Status colors are semantic tokens (`success`, `warning`, `danger`, `info`, room-status tokens), not arbitrary palettes.
- Internal operations UI favors information density and hierarchy over decorative card grids, giant gradients, or marketing-style visual noise.
- Dark mode is not assumed unless product scope explicitly adds it; tokens must still make it feasible later.
