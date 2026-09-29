import { create } from 'zustand'

/**
 * Proof that a group's password was given, for as long as the server
 * honours it.
 *
 * Not the same thing as the chat password in chatUnlock. That one is a
 * person's own arrangement over their own copy of a chat; this one belongs
 * to the group, is set by whoever runs it, and is asked of everybody in it.
 * Somebody can be behind both at once, so the two proofs are carried
 * separately and neither stands in for the other.
 *
 * Kept per conversation, because that is what a request names. Held in
 * memory and nowhere else, so a reload or a closed tab locks the group
 * again - a proof that survived a refresh would hand the group to whoever
 * picked up the phone.
 */
type GroupUnlockState = {
  /** conversation uuid -> the token that opens it */
  tokens: Record<string, string>
  set: (conversationUuid: string, token: string) => void
  forget: (conversationUuid: string) => void
  clear: () => void
}

export const useGroupUnlock = create<GroupUnlockState>((set) => ({
  tokens: {},
  set: (conversationUuid, token) => set((s) => ({ tokens: { ...s.tokens, [conversationUuid]: token } })),
  forget: (conversationUuid) => set((s) => {
    const { [conversationUuid]: _gone, ...rest } = s.tokens

    return { tokens: rest }
  }),
  clear: () => set({ tokens: {} }),
}))

export const GROUP_UNLOCK_HEADER = 'X-Group-Unlock'

/** The conversation a request is about, when it is about one. */
export function conversationInUrl(url: string | undefined): string | null {
  return /^\/conversations\/([0-9a-f-]{36})\b/i.exec(url ?? '')?.[1] ?? null
}

/** The header for this request, when there is a proof that fits it. */
export function groupUnlockHeaders(url: string | undefined): Record<string, string> {
  const conversation = conversationInUrl(url)
  const token = conversation ? useGroupUnlock.getState().tokens[conversation] : null

  return token ? { [GROUP_UNLOCK_HEADER]: token } : {}
}
