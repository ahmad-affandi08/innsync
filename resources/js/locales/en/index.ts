import { common } from './common.ts'
import { foundation } from './foundation.ts'
import { identity } from './identity.ts'
import { offline } from './offline.ts'
import { ui } from './ui.ts'

/** English is the source dictionary: it defines the key set every other locale must satisfy. */
export const en = { ...common, ...foundation, ...identity, ...offline, ...ui } as const

export type MessageKey = keyof typeof en
