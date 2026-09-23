import { create } from 'zustand'

/**
 * Which attachments this person has actually taken down.
 *
 * Whoever sent a file already has it - they chose it off their own disk a
 * moment ago - so their side shows it at once. Everybody else asks for it
 * first: a thread of screenshots should not spend somebody's data before
 * they have said they want to look.
 *
 * And until they have looked, there is nothing for them to pass on: a
 * picture cannot be forwarded, replied to or reacted to sight unseen, which
 * is how a file nobody has opened ends up in three more chats.
 *
 * Kept for the life of the tab rather than saved: it is about what has been
 * fetched into this page, and a reload has fetched nothing.
 */
type OpenedState = {
  ids: Set<number>
  markOpened: (id: number) => void
}

export const useOpenedAttachments = create<OpenedState>((set) => ({
  ids: new Set<number>(),
  markOpened: (id) => set((state) => {
    if (state.ids.has(id)) return state

    const ids = new Set(state.ids)
    ids.add(id)

    return { ids }
  }),
}))

/** Has this attachment been taken down in this tab? */
export const attachmentOpened = (id: number): boolean => useOpenedAttachments.getState().ids.has(id)
