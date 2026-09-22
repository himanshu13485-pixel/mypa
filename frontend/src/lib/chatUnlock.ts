import { create } from 'zustand'
import { useAuthStore } from '../stores/auth'

/**
 * Proof that the chat password was entered, for as long as the server
 * honours it.
 *
 * Held in memory and nowhere else - not localStorage, not a cookie - so a
 * reload, a closed tab or a restarted app locks everything again. That is
 * the whole point of the lock: it protects against the person who picks up
 * a phone left on a desk, and a proof that survived a refresh would hand
 * them the chats along with the phone.
 *
 * The server decides when it has run out (a quarter of an hour unused) and
 * answers 423; the API client clears it here when that happens, and every
 * locked screen falls back to asking.
 */
type ChatUnlockState = {
  token: string | null
  set: (token: string) => void
  clear: () => void
}

/** Matches the server's window (ChatLock::WINDOW_MINUTES). */
export const IDLE_RELOCK_MINUTES = 15

/*
 * Relocked by the person's absence, not by the server's clock alone.
 *
 * The server closes an unlock that goes unused - but the app is never
 * silent: the chat list refreshes every twenty seconds and an open thread
 * every fifteen, and each of those counts as use. Left to the server, a
 * tab sitting open on a desk would stay unlocked for ever.
 *
 * So the app watches the only thing that means somebody is there - taps,
 * keys, scrolling - and forgets the unlock after a quarter of an hour of
 * none of it, whatever the background refreshing is doing.
 */
let lastActivity = Date.now()
let watcher: number | null = null
const ACTIVITY = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const
const touch = () => { lastActivity = Date.now() }

function watchForIdle() {
  if (watcher !== null || typeof window === 'undefined') return
  lastActivity = Date.now()
  ACTIVITY.forEach((e) => window.addEventListener(e, touch, { passive: true }))
  watcher = window.setInterval(() => {
    if (Date.now() - lastActivity > IDLE_RELOCK_MINUTES * 60_000) useChatUnlock.getState().clear()
  }, 30_000)
}

function stopWatching() {
  if (watcher === null || typeof window === 'undefined') return
  ACTIVITY.forEach((e) => window.removeEventListener(e, touch))
  window.clearInterval(watcher)
  watcher = null
}

export const useChatUnlock = create<ChatUnlockState>((set) => ({
  token: null,
  set: (token) => { watchForIdle(); set({ token }) },
  clear: () => { stopWatching(); set({ token: null }) },
}))

export const CHAT_UNLOCK_HEADER = 'X-Chat-Unlock'

/** The header, when there is anything to send. */
export function unlockHeaders(): Record<string, string> {
  const token = useChatUnlock.getState().token

  return token ? { [CHAT_UNLOCK_HEADER]: token } : {}
}

/**
 * Headers for the attachment downloads, which go round the API client.
 *
 * Voice notes, photos and files are fetched raw so they can become blobs,
 * and a raw fetch carries nothing it is not given - so in a locked chat,
 * with the password entered, they were still being refused.
 */
export function attachmentHeaders(): Record<string, string> {
  return { Authorization: `Bearer ${useAuthStore.getState().token}`, ...unlockHeaders() }
}

/**
 * Is this search text the key to the hidden folder?
 *
 * "#123456#" - the password between two hashes, and nothing else. The
 * length bounds are the password's own (4 to 32), so an ordinary search for
 * "#1#" or a hashtag is left alone.
 */
export function hiddenFolderPassword(text: string): string | null {
  const match = /^#(.{4,32})#$/.exec(text.trim())

  return match ? match[1] : null
}

/**
 * Does a search mean "me"?
 *
 * The chat with yourself has no name to search for - it is you - so it is
 * offered for the words people actually type: their own name, handle or
 * Netvork ID, or "me", "self", "note".
 */
export function searchMeansMe(
  text: string,
  me: { name?: string | null; username?: string | null; app_id?: string | null } | null | undefined,
): boolean {
  const needle = text.trim().toLowerCase()
  if (needle.length < 2) return false
  if (['me', 'self', 'myself', 'you', 'note', 'notes', 'note to self'].some((w) => w.startsWith(needle) && needle.length >= 2 && w.length >= needle.length)) {
    return true
  }

  return [me?.name, me?.username, me?.app_id].some((v) => !!v && v.toLowerCase().includes(needle))
}
