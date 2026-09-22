import { create } from 'zustand'
import type { MailFull } from '../../../api/mails'

/**
 * What the compose window is writing, opened from anywhere in Mails.
 *
 * One window at a time: a second Compose while one is open brings the
 * first to the front rather than stacking drafts nobody can find.
 */
export type ComposeInit = {
  mode: 'new' | 'reply' | 'replyAll' | 'forward' | 'draft'
  source?: MailFull
  draft?: MailFull
  account?: string | null
  to?: string[]
}

type ComposeState = {
  current: ComposeInit | null
  /** The mail that has just been sent and can still be pulled back. */
  undo: { uuid: string; until: number } | null
  open: (init: ComposeInit) => void
  close: () => void
  setUndo: (undo: { uuid: string; until: number } | null) => void
}

export const useComposer = create<ComposeState>((set) => ({
  current: null,
  undo: null,
  open: (init) => set({ current: init }),
  close: () => set({ current: null }),
  setUndo: (undo) => set({ undo }),
}))

/** Which mailbox the Mails screens are showing: one of them, or all together. */
export const useMailView = create<{ account: string; setAccount: (account: string) => void }>((set) => ({
  account: 'all',
  setAccount: (account) => set({ account }),
}))
