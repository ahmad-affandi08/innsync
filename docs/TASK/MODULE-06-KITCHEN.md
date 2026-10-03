# Kitchen — Task Contract

**Bounded context:** Kitchen
**Critical note:** KDS/recipe/production facts feed inventory; do not mutate inventory tables directly.

## Candidate aggregates / read models

- `KitchenTicket`
- `Recipe`
- `Production/WasteRecord`
- `MenuAvailability`

## Requirement backlog

| Task ID | FR | Priority | Requirement | Status |
| --- | --- | --- | --- | --- |
| TASK-KIT-001 | FR-KIT-001 | Wajib | Menampilkan tiket pesanan dari POS pada layar dapur secara berurutan beserta waktu tunggu dan penanda keterlambatan. | REVIEW |
| TASK-KIT-002 | FR-KIT-002 | Wajib | Mengubah status tiket menjadi diproses, siap, dan sudah diantar sehingga pelayan menerima pemberitahuan. | REVIEW |
| TASK-KIT-003 | FR-KIT-003 | Wajib | Mengelola resep dan komposisi bahan (bill of material) berversi untuk setiap menu, termasuk yield, waste standar, satuan, dan tanggal efektif, sebagai dasar biaya bahan dan harga pokok. | REVIEW |
| TASK-KIT-004 | FR-KIT-004 | Wajib | Mengurangi stok bahan secara otomatis berdasarkan versi resep yang berlaku setiap kali item menu diposting sebagai penjualan, tepat satu kali untuk setiap transaksi. | REVIEW |
| TASK-KIT-005 | FR-KIT-005 | Wajib | Menandai menu yang habis sehingga otomatis tidak dapat dipesan dari POS maupun menu QR tamu. | IN_PROGRESS |
| TASK-KIT-006 | FR-KIT-006 | Wajib | Mencatat pemakaian bahan, produksi persiapan, dan pembuangan bahan rusak (waste log) beserta alasan. | IN_PROGRESS |
| TASK-KIT-007 | FR-KIT-007 | Wajib | Melakukan stock opname bahan dapur dan gudang kering dengan pencatatan selisih dan nilai kerugian. | REVIEW |
| TASK-KIT-008 | FR-KIT-008 | Wajib | Menampilkan daftar periksa kebersihan, suhu penyimpanan, dan tugas harian, mingguan, serta bulanan dapur. | REVIEW |
| TASK-KIT-009 | FR-KIT-009 | Sebaiknya | Mencatat tanggal kedaluwarsa dan nomor batch bahan sensitif dengan peringatan mendekati kedaluwarsa. | REVIEW |
| TASK-KIT-010 | FR-KIT-010 | Wajib | Membuat laporan kerusakan peralatan yang diteruskan ke modul Maintenance. | REVIEW |
| TASK-KIT-011 | FR-KIT-011 | Wajib | Mengajukan permintaan pembelian bahan dan peralatan ke modul Purchasing. | REVIEW |
| TASK-KIT-012 | FR-KIT-012 | Sebaiknya | Menerbitkan laporan penjualan menu, rasio biaya bahan terhadap penjualan, dan analisis menu berdasarkan popularitas serta kontribusi margin. | REVIEW |
| TASK-KIT-013 | FR-KIT-013 | Wajib | Setiap perubahan resep menghasilkan versi baru bertanggal efektif; transaksi lama selalu mereferensikan versi resep yang berlaku saat transaksi diposting. | REVIEW |
| TASK-KIT-014 | FR-KIT-014 | Sebaiknya | Mendukung produksi/preparation batch (misalnya sauce, dough, stock) yang mengonsumsi bahan baku dan menghasilkan semi-finished goods beserta yield aktual. | TODO |
| TASK-KIT-015 | FR-KIT-015 | Wajib | KDS menyediakan indikator koneksi dan antrean; bila layar atau jaringan bermasalah, tiket tetap tersimpan dan dapat dialihkan ke printer/fallback queue tanpa kehilangan order. | IN_PROGRESS |

## Progress notes

### Slice 24 (2026-10-03): the kitchen and bar screen

- Status: `TASK-KIT-001` and `-002` are `REVIEW`. `TASK-KIT-005` and `-015` are `IN_PROGRESS`: sold out blocks the point of sale and the screen shows its age, but the guest QR menu does not exist yet and a printer fallback is not built. ADR/BR: property scope, the Application layer reads other contexts only through contracts and events, audit of every setting and of every sold-out change.
- Context: new bounded context `Kitchen` (`app/Modules/Kitchen`), migration 75 (`kitchen_tickets`, `kitchen_ticket_lines`, `kitchen_settings`, and `prep_status` on `fnb_bill_lines`), `TicketStore` with `DatabaseTicketStore`, `TicketIntakeConsumer`, `BoardService`, `BoardController`, page `kitchen/pages/board`, routes `/kitchen`. Permissions `kitchen.board.operate` (works the screen, marks dishes sold out) and `kitchen.settings.manage` (sets the waiting limit).
- **Tickets.** The point of sale publishes `fnb.order.sent`; the kitchen turns it into one ticket per station (kitchen or bar) with the table, room or counter it goes to, the lines with variant, choices and note, and the moment it was sent. A line no station prepares never reaches a screen and is served when sent. Handling the same send twice makes its tickets once (unique per send and station). A line voided, or a bill cancelled, strikes its dishes off the screens (a ticket left with no dish is cancelled), so nobody cooks what will not be paid.
- **Steps.** New, preparing, ready, served. A cook may also finish a ticket that was never started. Whoever moves a ticket and when are kept; a move names the version of the ticket the cook saw, so two screens cannot move it twice. Every move publishes `kitchen.ticket.progressed`; the point of sale reads it (`KitchenProgressConsumer`, in F&B) and shows the waiter how far each line is on the bill, and how many lines are ready on the table of the floor plan.
- **Waiting and late.** The screen shows how long a ticket waited, counting up each second against the server's clock (a wrong clock on the device does not matter). A new or preparing ticket waiting longer than the limit is marked late. Baseline: 15 minutes; a manager sets 1 to 240 minutes with a reason, audited, with the version of the setting.
- **Sold out (`KIT-005`).** A tab lists the menu; marking a dish sold out goes through the `MenuAvailability` contract (F&B keeps the menu and audits the change as set by the kitchen), and the point of sale refuses to order it until it is put back.
- **Connection (`KIT-015`).** The screen reloads itself every 15 seconds and says when it last got the tickets; when the network fails it keeps what it has, says so with the time of that picture, and keeps trying. Tickets are stored whatever the state of a screen, so nothing is lost; a printer or fallback queue for a dead screen is not built.
- Not yet: recipes and stock deduction (`KIT-003`, `-004`, `-013`), waste log (`KIT-006`), preparation batches, the guest QR menu that must also honour sold out, a ticket printer, sound alerts, per-item prep times.
- Evidence: `tests/Feature/Kitchen/BoardHttpTest.php` (one ticket per station and none for what no station prepares, place of table, room and counter, the steps with lock and who, what the waiter sees on the bill and the floor, void and cancel take dishes off the screens, late by the property's limit and its audited setting, sold out blocks ordering and putting back allows it, permissions).

### Slice 25 (2026-10-03): recipes and stock taken out by what is sold

- Status: `TASK-KIT-003`, `-004` and `-013` are `REVIEW`. ADR/BR: BR-005 (a document takes stock once), BR-007 (negative-stock policy), the Kitchen context never changes inventory tables, it publishes a fact the inventory consumes.
- Context: migration 76 (`kitchen_recipes`, `kitchen_recipe_versions`, `kitchen_recipe_lines`, `kitchen_consumptions`, and `stock_location_id` on `kitchen_settings`; versions, their lines and consumptions are append-only by trigger), `RecipeStore` with `DatabaseRecipeStore`, `RecipeService`, `SaleConsumptionConsumer`, `RecipeController`, page `kitchen/pages/recipes`, routes `/kitchen/recipes`. In the inventory: the `IngredientCatalog` contract (items with their units, locations, the value of a quantity at the moving average) and `RecipeConsumptionConsumer`. Permission `kitchen.recipe.manage` writes recipes; whoever works the screen may read them.
- **Recipes (`KIT-003`).** One recipe per dish: what a batch takes (ingredients from the inventory in any of their units, a quantity to three decimals and a standard waste of 0 to 50 percent each) and the portions the batch makes (the yield). The page lists the dishes with their recipe, the version in force, the cost of a portion at the moving average of the stock and the food cost against the menu price; a cost that cannot be known yet (an ingredient never received) is marked partial, not guessed.
- **Versions (`KIT-013`).** A recipe is never edited. A change is a new version with an effective date (today or later; the first one too) and a reason, audited with the version it replaces. A version may be scheduled; the old one stays in force until its date. A day that has passed is never touched.
- **Stock (`KIT-004`).** When a bill is settled the kitchen reads the event, takes the version in force on the day of settlement for each sold line, and works out each ingredient: the recipe quantity for the yield, times the portions, plus the waste, rounded up to a thousandth. It keeps one row for each line and ingredient with the version used (so a sale always points at its recipe, and handling the event twice changes nothing), then tells the inventory, which posts one issue movement for each, the sold line being the source (exactly once, BR-005). Dishes without a recipe take nothing. Consumption feeds the department `kitchen` of the stock reports, so the food cost report of finance reads it without change.
- **Where from, and when stock is short.** The location the ingredients are taken from is set with the kitchen screen settings. With recipes in force and no location the message is not taken and waits (visible as a delivery that retries), rather than guessing a store. A sale is never refused because the books are behind: the balance may go below zero, with the reason on the movement, unless the location or the category blocks negative stock, and then the message waits for a person.
- Not yet: a different recipe for each size or kind of a dish (a recipe covers every variant of its item), choices that consume extra stock, reversing consumption for a refunded bill (comes with refunds), the waste log for dishes voided after being cooked (`KIT-006`), preparation batches and semi-finished goods (`KIT-014`), the menu cost and margin analysis report (`KIT-012`).
- Evidence: `tests/Feature/Kitchen/RecipeHttpTest.php` (the rounding rule, versions with their rules and that they cannot be changed, cost of a portion, a settled bill takes stock once with the movement values and version, the version of the day is used and a dish without recipe takes nothing, no location waits, negative stock allowed by sales unless blocked, permissions). Seen in the browser: a recipe written from the page, cost 4,650 per portion (10.3 percent), a bill paid, the stock taken and listed, in English and Indonesian.

### Slice 28 (2026-10-03): the waste log

- Status: `TASK-KIT-006` is `IN_PROGRESS`: the waste log is built; recording the use of ingredients and preparation production is not. ADR/BR: BR-005 (stock written off once per entry), BR-007 (negative-stock policy), append-only log, audit.
- Context: migration 80 (`kitchen_waste`, `kitchen_waste_lines`, append-only by trigger), `WasteStore` with `DatabaseWasteStore`, `WasteService`, `WasteController`, page `kitchen/pages/waste`, routes `/kitchen/waste`, privilege `kitchen.waste.record` (whoever works the screen may read the log); in the inventory `KitchenWasteConsumer`.
- **Entries.** An ingredient is thrown away by its quantity (three decimals, any unit the inventory counts it in); a dish by its portions, which the recipe in force turns into the ingredients it took (the same rule as a sale, standard waste included). A dish with no recipe in force cannot be recorded: record its ingredients instead. Each entry has a number (`KWL-000001`), a reason (spoiled, expired, dropped or damaged, overcooked or burnt, sent back by the guest, wrong order, other; other needs a note), an optional reference such as the bill, who recorded it and when, and what it cost at the moving average of the stock (partial when an ingredient has no cost yet). The page shows today's cost and the last 30 days by reason.
- **Stock.** The kitchen publishes the entry and the inventory writes the stock off, one movement per ingredient with the entry as the source (once). The location is the one the kitchen takes its ingredients from; without it nothing is recorded. Waste is real whatever the books say, so the balance may go below zero unless the location or the category blocks it, and then the message waits. Reasons the inventory does not know are written as other. Stock written off is cost in the food cost report of finance.
- The log is never edited or removed; a wrong entry is answered with a stock adjustment.
- Not yet: the use of ingredients and preparation batches in the same log, photos of what was thrown away, a threshold that needs a second person, waste proposed automatically from a void after the dish was cooked.
- Evidence: `tests/Feature/Kitchen/WasteHttpTest.php` (an ingredient logged, costed and written off once; a dish worked out by its recipe; the checks and that the log cannot be changed; negative stock allowed unless blocked; permissions). Seen in the browser: two portions of a dish sent back, recorded, listed with its ingredients and its cost.

### Slice 42 (2026-10-03): equipment faults and purchase requests of the kitchen

- Status: `TASK-KIT-010` and `-011` are `REVIEW`. Equipment faults of the kitchen and bar are reported the same way as in housekeeping (department `kitchen`, privilege `kitchen.damage.report`, or the board, waste or recipe privilege) and become work orders of Maintenance. The kitchen menu links to the purchase requests of the department (`/inventory/requests?department=kitchen`): the list shows only the department's requests and a new request starts for it; the request, its approval chain and its ordering are Purchasing's.
- Context: `EquipmentDamageReportService`, `DamageReportController`, page `kitchen/pages/damage-reports`; `PurchaseRequestService::overview` takes an optional department.
- Evidence: `tests/Feature/Maintenance/DamageReportHttpTest.php`, `tests/Feature/InventoryPurchasing/PurchasingHttpTest.php` (the department view).

- `TASK-KIT-009` is `REVIEW` (same slice): the kitchen menu links to the batches of kitchen items (`/inventory/lots?department=kitchen`) with the warning of batches that are about to expire or have expired; the batch and expiry rules are Inventory's (`INV-008`).

- `TASK-KIT-007` is `REVIEW` (slice 45): the kitchen menu opens the counts of the kitchen's own stores (`/inventory/counts?location_kind=kitchen`) and the requests to the main store; the count, its second-person review, the differences and their value are Inventory's (`INV-006`, `INV-012`).

### Slice 46 (2026-10-03): checklists and storage temperatures of the kitchen and the outlets

- Status: `TASK-KIT-008` and `TASK-FBS-032` (in `MODULE-05-FNB-SERVICE.md`) are `REVIEW`. The kitchen and the outlets each keep daily, weekly and monthly checklists (hygiene rounds, closing and cleaning tasks): management writes a template (a change is a new version), the period has one run of each active checklist started by the first tick with the items it began with, a ticked item is a fact (who, when, a note) and each one is announced for Human Resource with the share of the checklist done so far (events `kitchen.sop.item_completed` and `fnb.sop.item_completed`, and `.run_completed`). The kitchen (and the outlets) also keep storage temperatures: management writes the places (chiller, freezer) with the range of each in tenths of a degree; a reading is judged against the range of the moment, a reading outside it needs the action taken and is announced (`.temperature.out_of_range`); readings are never changed.
- Context: new module `Routines` (`RoutineSopService`, `TemperatureService`, `RoutineStore`, `RoutineController`, pages `routines/pages/{checklists,templates,performance,temperatures}`), migration 95, privileges `kitchen.sop.*`, `fnb.sop.*` (manage, perform, view) and `*.temperature.record`; the two menus link to the pages.
- Not yet: reminders for checklists not done, photographing an item, a reading from a sensor.
- Evidence: `tests/Feature/Routines/RoutineHttpTest.php` (versions and their checks, the board per department, ticking and the events, the run keeping its items, who may, the performance figure; points and ranges, in-range and outside readings, the range kept with each reading, inactive places, who may, the triggers).

### Slice 60 (2026-10-03): the menu report

- Status: `TASK-KIT-012` is `REVIEW`.
- Context: `MenuSales` (FnbSales contract, `DatabaseMenuSales`), `RecipeStore::consumedBetween`, `MenuReportService`, `MenuReportController`, page `kitchen/pages/menu-report.tsx`, privilege `kitchen.report.view` (the recipe privilege also reads it).
- **Sales.** Settled bills of the period by business date (a refunded or cancelled bill, an open bill and a voided or removed line never count), per dish and outlet: portions, sales net of discounts, the service charge and the tax (each line's share of what the bill kept), the discounts given and the average price.
- **Cost and ratio.** The cost of a dish is what the period's sales took out of the pantry by the recipe of each day (`kitchen_consumptions`), valued at the moving average of the inventory now; a dish whose ingredients are not all costed is shown as partial, one with no recipe (or nothing taken yet) shows none, and neither enters the cost ratio of the total. Per dish: cost, cost ratio of its sales, margin per portion and share of the portions.
- **Menu engineering.** A dish with a complete cost and a sale is classed when at least two are: popular when its share of the portions of the costed dishes is at least 70% of an equal share, high margin when its margin per portion reaches the average of those dishes weighted by portions; star, plowhorse, puzzle or dog, with what to do about each on the screen. The period is at most 366 days and defaults to the last 30 days of the business date; it can be narrowed to an outlet.
- Not yet: cost at the price paid on the day (the average of now is used, so an old period is costed at today's average), cost of choices and variants that consume stock, a trend by week, and export.
- Evidence: `tests/Feature/Kitchen/MenuReportHttpTest.php` (sales, cost, ratio, margin, mix and the class of each dish from real settled bills and consumptions, the outlet and period filters, open bills and an uncosted ingredient, rights and dates).

## Required engineering checks

- Identify aggregate owner and state transition before coding.
- Enforce property scope and server-side authorization.
- Define transaction/idempotency/concurrency behavior where mutation is critical.
- Emit audit evidence for sensitive/state-changing operations.
- Add happy, negative, conflict/retry, and permission tests as applicable.
- Update traceability/evidence before marking DONE.
