/**
 * Whether this device rings for a task or a bill falling due.
 *
 * Per device, like every other sound preference: whether a phone on a desk
 * should make a noise is a fact about the phone, not about the account. Per
 * module too, because wanting to be told about money is not the same as
 * wanting to be told about a to-do.
 *
 * Reads that fail — private mode, storage switched off — come back as "on",
 * which is the answer that keeps a due date from passing quietly.
 */
const KEY = 'netvork-due-alerts'

export interface ModuleAlertPrefs {
  /** Alert at all. Off means this module never interrupts. */
  enabled: boolean
  /** Make a noise as well as showing the box. */
  sound: boolean
}

export interface DueAlertPrefs {
  task: ModuleAlertPrefs
  bill: ModuleAlertPrefs
}

const DEFAULTS: DueAlertPrefs = {
  task: { enabled: true, sound: true },
  bill: { enabled: true, sound: true },
}

export function getDueAlertPrefs(): DueAlertPrefs {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return DEFAULTS
    const saved = JSON.parse(raw) as Partial<DueAlertPrefs>

    return {
      task: { ...DEFAULTS.task, ...saved.task },
      bill: { ...DEFAULTS.bill, ...saved.bill },
    }
  } catch {
    return DEFAULTS
  }
}

export function setDueAlertPrefs(prefs: DueAlertPrefs): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(prefs))
  } catch {
    // Nothing to be done about it, and nothing that should stop the alarm:
    // the preference simply lasts as long as the tab does.
  }
}
