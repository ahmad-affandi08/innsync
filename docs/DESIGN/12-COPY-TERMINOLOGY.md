# Copy and Terminology

Use PRD glossary consistently. Do not invent synonyms for folio, business date, night audit, OOO/OOS, ADR, RevPAR, work order, PR/PO, stock opname, service charge, idempotency, or payment unknown.

UI labels may be Indonesian/English via localization keys. Internal enum/database values remain stable language-neutral codes.

## One word in the Indonesian screens (2026-11-10)

| Meaning | Indonesian screens | English screens |
| --- | --- | --- |
| business date | Tanggal bisnis | Business date |
| night audit | Night audit | Night audit |
| room charge | Biaya kamar | Room charge |
| folio, posting | Folio, posting (hotel terms staff already use) | Folio, posting |

Do not write "Business date", "Tanggal operasional", "Audit malam" or "Room charge" in an Indonesian string: `resources/js/shared/i18n/glossary.test.ts` fails on them. Add a row here before adding a new term of this kind.

