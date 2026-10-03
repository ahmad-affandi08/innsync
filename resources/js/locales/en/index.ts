import { common } from './common.ts'
import { finance } from './finance.ts'
import { fnb } from './fnb.ts'
import { guest } from './guest.ts'
import { guide } from './guide.ts'
import { kitchen } from './kitchen.ts'
import { maintenance } from './maintenance.ts'
import { hr } from './hr.ts'
import { foundation } from './foundation.ts'
import { frontOffice } from './frontoffice.ts'
import { housekeeping } from './housekeeping.ts'
import { identity } from './identity.ts'
import { inventory } from './inventory.ts'
import { purchasingReceiving } from './purchasing-receiving.ts'
import { laundry } from './laundry.ts'
import { offline } from './offline.ts'
import { property } from './property.ts'
import { purchasingReports } from './purchasing-reports.ts'
import { reporting } from './reporting.ts'
import { routines } from './routines.ts'
import { rates } from './rates.ts'
import { ui } from './ui.ts'

/** English is the source dictionary: it defines the key set every other locale must satisfy. */
export const en = { ...common, ...finance, ...fnb, ...guest, ...guide, ...kitchen, ...maintenance, ...hr, ...foundation, ...frontOffice, ...housekeeping, ...identity, ...inventory, ...purchasingReceiving, ...laundry, ...offline, ...property, ...purchasingReports, ...rates, ...reporting, ...routines, ...ui } as const

export type MessageKey = keyof typeof en
