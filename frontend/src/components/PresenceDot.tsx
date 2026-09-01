import { clsx } from 'clsx'
import {
  PRESENCE_DOT,
  PRESENCE_LABELS,
  PRESENCE_TEXT,
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
 * The word, in the dot's colour.
 *
 * `fallback` is what the line said before presence existed — an App ID, a
 * member count — and is what still shows when there is no state to report.
 * The alternative, a permanent "Not available" under every hidden name, tells
 * the reader less than the App ID did.
 */
export function PresenceLabel({
  state,
  fallback,
  className,
}: {
  state: PresenceState | null | undefined
  fallback?: React.ReactNode
  className?: string
}) {
  if (!state) return <>{fallback ?? null}</>

  return (
    <span className={clsx('font-medium', PRESENCE_TEXT[state], className)}>
      {PRESENCE_LABELS[state]}
    </span>
  )
}

/**
 * Both together, for a line that has room for them — the chat header, a row
 * in the members dialog — where the dot has no avatar to sit on.
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
    <span className={clsx('inline-flex items-center gap-1', className)}>
      <span className={clsx('size-2 shrink-0 rounded-full', PRESENCE_DOT[state])} />
      <span className={clsx('font-medium', PRESENCE_TEXT[state])}>{PRESENCE_LABELS[state]}</span>
    </span>
  )
}
