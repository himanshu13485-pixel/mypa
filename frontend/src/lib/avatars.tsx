/**
 * The picture next to a person's name.
 *
 * Every list, tile and chat bubble in the app drew the first letter of a name
 * in a coloured disc, and the photo people had uploaded — carried all the way
 * from the server as `photo_path` — was never displayed anywhere. One
 * component now answers "what does this person look like?" in one order,
 * everywhere:
 *
 *   1. the photo they uploaded, if they uploaded one
 *   2. the illustration they picked
 *   3. an illustration matching the gender on their profile, if we know it
 *   4. their initial
 *
 * The illustrations were hand-drawn here as inline SVG — twelve faces built
 * from a few skin, hair and style parameters — and they looked it. They are
 * now Open Peeps by Pablo Stanley, drawn once by scripts/build-avatars.mjs and
 * committed as files. Open Peeps is CC0: public domain, nothing owed, no
 * credit to carry.
 *
 * Imported rather than inlined. Twelve of them is about 106 KB of markup, and
 * putting that in the bundle would make every page in the app pay for artwork
 * most of them never show. Imported, Vite emits each as its own hashed file,
 * so they are fetched only when an avatar is actually drawn, cached for ever
 * on a URL that changes if the picture does, and absent from the JavaScript
 * entirely.
 */
import { clsx } from 'clsx'

import f1 from '../assets/avatars/f1.svg'
import f2 from '../assets/avatars/f2.svg'
import f3 from '../assets/avatars/f3.svg'
import f4 from '../assets/avatars/f4.svg'
import f5 from '../assets/avatars/f5.svg'
import f6 from '../assets/avatars/f6.svg'
import m1 from '../assets/avatars/m1.svg'
import m2 from '../assets/avatars/m2.svg'
import m3 from '../assets/avatars/m3.svg'
import m4 from '../assets/avatars/m4.svg'
import m5 from '../assets/avatars/m5.svg'
import m6 from '../assets/avatars/m6.svg'

/** Key format: f/m + 1-6. Stored on profiles, so these names are permanent. */
export type AvatarKey = string

/** Every illustration on offer, by the key kept on the profile. */
export const AVATARS: Record<AvatarKey, string> = {
  f1, f2, f3, f4, f5, f6,
  m1, m2, m3, m4, m5, m6,
}

/** Grouped for the picker, in the order they are offered. */
export const AVATAR_GROUPS: { label: string; keys: AvatarKey[] }[] = [
  { label: 'Female', keys: ['f1', 'f2', 'f3', 'f4', 'f5', 'f6'] },
  { label: 'Male', keys: ['m1', 'm2', 'm3', 'm4', 'm5', 'm6'] },
]

/**
 * Something better than an initial when we know the gender but they have not
 * picked. Anything else — unset, "other", "prefer not to say" — gets the
 * initial rather than a guess.
 */
export function defaultAvatarFor(gender?: string | null): AvatarKey | null {
  // Whole words, not first letters. "woman" begins with a w, and matching on
  // the initial would also claim "fluid" and "man" out of "non-binary man".
  const g = (gender ?? '').trim().toLowerCase()
  if (['f', 'female', 'woman', 'women', 'girl'].includes(g)) return 'f1'
  if (['m', 'male', 'man', 'men', 'boy'].includes(g)) return 'm1'

  return null
}

export function Avatar({
  name,
  photoPath,
  avatar,
  gender,
  size = 36,
  className,
  ring,
}: {
  name?: string | null
  photoPath?: string | null
  avatar?: string | null
  gender?: string | null
  size?: number
  className?: string
  /** A hairline round the edge, for avatars drawn over a photo or video. */
  ring?: boolean
}) {
  const shell = clsx(
    // inline-flex, not the default inline: width and height simply do not
    // apply to an inline box, so a 52px avatar drew at its intrinsic size and
    // the picker came out as a column of enormous faces.
    'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full',
    ring && 'ring-2 ring-white/70 dark:ring-slate-900/70',
    className,
  )
  const style = { width: size, height: size }

  if (photoPath) {
    return (
      <img
        src={photoUrl(photoPath)}
        alt={name ?? ''}
        style={style}
        className={clsx(shell, 'bg-slate-200 object-cover dark:bg-slate-700')}
      />
    )
  }

  const art = AVATARS[avatar ?? defaultAvatarFor(gender) ?? '']
  if (art) {
    return (
      <img
        src={art}
        alt={name ?? ''}
        style={style}
        // The disc is drawn into the artwork, so nothing here needs a
        // background — and object-cover keeps it round if a caller passes a
        // non-square size.
        className={clsx(shell, 'object-cover')}
        title={name ?? undefined}
      />
    )
  }

  return (
    <span
      style={{ ...style, fontSize: Math.max(10, Math.round(size * 0.42)) }}
      className={clsx(shell, 'flex items-center justify-center bg-brand-600 font-semibold uppercase text-white')}
      title={name ?? undefined}
    >
      {(name ?? '?').charAt(0)}
    </span>
  )
}

/**
 * Where an uploaded photo actually lives.
 *
 * The server stores a path inside the public disk ("profile-photos/x.jpg");
 * the browser needs it under /storage. Absolute URLs are passed through, so a
 * future move to a CDN needs no change here.
 */
export function photoUrl(path: string): string {
  return /^https?:\/\//.test(path) ? path : `/storage/${path.replace(/^\/+/, '')}`
}
