import { common } from './common.ts'
import { foundation } from './foundation.ts'
import { frontOffice } from './frontoffice.ts'
import { housekeeping } from './housekeeping.ts'
import { identity } from './identity.ts'
import { laundry } from './laundry.ts'
import { offline } from './offline.ts'
import { property } from './property.ts'
import { rates } from './rates.ts'
import { ui } from './ui.ts'

/** English is the source dictionary: it defines the key set every other locale must satisfy. */
export const en = { ...common, ...foundation, ...frontOffice, ...housekeeping, ...identity, ...laundry, ...offline, ...property, ...rates, ...ui } as const

export type MessageKey = keyof typeof en
