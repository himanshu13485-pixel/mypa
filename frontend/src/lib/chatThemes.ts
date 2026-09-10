/**
 * What a chat looks like.
 *
 * A theme is chosen per conversation and per person: it is a reading
 * preference, so changing yours never changes what the other side sees. The
 * names here mirror ConversationController::THEMES — the server refuses any
 * value it does not know, so the two lists have to agree.
 *
 * Every class is written out in full rather than composed from a colour name.
 * Tailwind reads this file as text; a class built at runtime would simply not
 * exist in the stylesheet.
 */
export interface ChatTheme {
  key: ChatThemeKey
  label: string
  /** The bubbles you send. */
  own: string
  /** The bubbles you receive. */
  theirs: string
  /** Behind the whole thread. */
  pane: string
  /** The two dots that stand for the theme in the picker. */
  swatch: [string, string]
}

export type ChatThemeKey =
  | 'default' | 'ocean' | 'forest' | 'violet' | 'rose' | 'sunset' | 'graphite' | 'midnight'

export const CHAT_THEMES: ChatTheme[] = [
  {
    key: 'default',
    label: 'Netvork',
    own: 'bg-brand-600 text-white',
    theirs: 'bg-slate-100 dark:bg-slate-800',
    pane: '',
    swatch: ['bg-brand-600', 'bg-slate-100 dark:bg-slate-700'],
  },
  {
    key: 'ocean',
    label: 'Ocean',
    own: 'bg-sky-600 text-white',
    theirs: 'bg-sky-50 text-slate-700 dark:bg-sky-950/60 dark:text-slate-100',
    pane: 'bg-sky-50/40 dark:bg-slate-900',
    swatch: ['bg-sky-600', 'bg-sky-100 dark:bg-sky-900'],
  },
  {
    key: 'forest',
    label: 'Forest',
    own: 'bg-emerald-600 text-white',
    theirs: 'bg-emerald-50 text-slate-700 dark:bg-emerald-950/60 dark:text-slate-100',
    pane: 'bg-emerald-50/40 dark:bg-slate-900',
    swatch: ['bg-emerald-600', 'bg-emerald-100 dark:bg-emerald-900'],
  },
  {
    key: 'violet',
    label: 'Violet',
    own: 'bg-violet-600 text-white',
    theirs: 'bg-violet-50 text-slate-700 dark:bg-violet-950/60 dark:text-slate-100',
    pane: 'bg-violet-50/40 dark:bg-slate-900',
    swatch: ['bg-violet-600', 'bg-violet-100 dark:bg-violet-900'],
  },
  {
    key: 'rose',
    label: 'Rose',
    own: 'bg-rose-600 text-white',
    theirs: 'bg-rose-50 text-slate-700 dark:bg-rose-950/60 dark:text-slate-100',
    pane: 'bg-rose-50/40 dark:bg-slate-900',
    swatch: ['bg-rose-600', 'bg-rose-100 dark:bg-rose-900'],
  },
  {
    key: 'sunset',
    label: 'Sunset',
    own: 'bg-amber-500 text-white',
    theirs: 'bg-amber-50 text-slate-700 dark:bg-amber-950/60 dark:text-slate-100',
    pane: 'bg-amber-50/40 dark:bg-slate-900',
    swatch: ['bg-amber-500', 'bg-amber-100 dark:bg-amber-900'],
  },
  {
    key: 'graphite',
    label: 'Graphite',
    own: 'bg-slate-700 text-white',
    theirs: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-100',
    pane: 'bg-slate-50 dark:bg-slate-900',
    swatch: ['bg-slate-700', 'bg-slate-200 dark:bg-slate-700'],
  },
  {
    // The one theme that ignores the app's light mode: some people read a
    // chat at night on a screen that is otherwise white.
    key: 'midnight',
    label: 'Midnight',
    own: 'bg-indigo-500 text-white',
    theirs: 'bg-slate-800 text-slate-100',
    pane: 'bg-slate-900',
    swatch: ['bg-indigo-500', 'bg-slate-800'],
  },
]

const FALLBACK = CHAT_THEMES[0]

/** The theme a conversation is wearing; the app's own colours when unset. */
export function chatTheme(key?: string | null): ChatTheme {
  if (!key) return FALLBACK

  return CHAT_THEMES.find((t) => t.key === key) ?? FALLBACK
}
