import { clsx } from 'clsx'
import {
  PRESENCE_DOT,
  PRESENCE_LABELS,
  PRESENCE_TITLES,
  type PresenceState,
} from '../lib/presence'

/**
 * The round button that rides on an avatar.
 *
 * Green here, amber stepped away, red gone. Drawn nowhere at all when the
 * state is null, which is what somebody who has hidden their status sends —
 * an absent dot is the only honest answer to a question they declined, and a
 * red one would be a different claim entirely.
 *
 * `border-white … dark:border-slate-900` is not decoration: an amber dot on a
 * light avatar and a red one on a dark photo both vanish without it.
 */
export function PresenceDot({
  state,
  className,
}: {
  state: PresenceState | null | undefined
  className?: string
}) {
  if (!state) return null

  return (
    <span
      title={PRESENCE_TITLES[state]}
      aria-label={PRESENCE_LABELS[state]}
      className={clsx(
        'absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white dark:border-slate-900',
        PRESENCE_DOT[state],
        className,
      )}
    />
  )
}

/**
 * The same dot, on a line of text rather than on an avatar.
 *
 * For the one place that has no avatar to hang it on: the chat header, which
 * names the person in words and has nothing round to put a button on.
 *
 * It used to say the word as well — "Online", "Away", "Not available" — and
 * the word has gone. Three states with three colours everybody already reads
 * do not need labelling twice, and the sentence it sat in was doing more
 * useful work: a handle, an App ID and a last-seen time all lost room to a
 * word that the dot beside it had already said. Green, amber and red mean what
 * they have always meant.
 *
 * The meaning is not lost for anybody who cannot use colour: the title says it
 * on hover and aria-label says it to a screen reader, which is where a word
 * belongs when a colour is carrying it.
 */
export function PresenceInline({
  state,
  className,
}: {
  state: PresenceState | null | undefined
  className?: string
}) {
  if (!state) return null

  return (
    <span
      title={PRESENCE_TITLES[state]}
      aria-label={PRESENCE_LABELS[state]}
      role="img"
      className={clsx('size-2 shrink-0 rounded-full', PRESENCE_DOT[state], className)}
    />
  )
}
