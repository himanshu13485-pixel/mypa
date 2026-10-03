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
  /**
   * Conversations the server has refused for want of a group password.
   *
   * The list says whether a group is locked, and the screen asks on the
   * strength of it - but the server is the one that decides, and it says so
   * in a 423. Remembering that means a chat whose row is wrong still shows
   * the box to type the password into, rather than an error nobody can act
   * on. It cost a released bug to learn that the flag alone is not enough.
   */
  sealed: Record<string, true>
  set: (conversationUuid: string, token: string) => void
  seal: (conversationUuid: string) => void
  forget: (conversationUuid: string) => void
  clear: () => void
}

export const useGroupUnlock = create<GroupUnlockState>((set) => ({
  tokens: {},
  sealed: {},
  set: (conversationUuid, token) => set((s) => {
    const { [conversationUuid]: _open, ...stillSealed } = s.sealed

    return { tokens: { ...s.tokens, [conversationUuid]: token }, sealed: stillSealed }
  }),
  seal: (conversationUuid) => set((s) => ({ sealed: { ...s.sealed, [conversationUuid]: true } })),
  forget: (conversationUuid) => set((s) => {
    const { [conversationUuid]: _gone, ...rest } = s.tokens

    return { tokens: rest, sealed: { ...s.sealed, [conversationUuid]: true } }
  }),
  clear: () => set({ tokens: {}, sealed: {} }),
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
