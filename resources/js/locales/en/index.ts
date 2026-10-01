import { common } from './common.ts'
import { foundation } from './foundation.ts'
import { frontOffice } from './frontoffice.ts'
import { identity } from './identity.ts'
import { offline } from './offline.ts'
import { property } from './property.ts'
import { rates } from './rates.ts'
import { ui } from './ui.ts'

/** English is the source dictionary: it defines the key set every other locale must satisfy. */
export const en = { ...common, ...foundation, ...frontOffice, ...identity, ...offline, ...property, ...rates, ...ui } as const

export type MessageKey = keyof typeof en
