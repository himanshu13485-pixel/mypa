/**
 * The picture next to a person's name.
 *
 * Every list, tile and chat bubble in the app drew the first letter of a
 * name in a coloured disc, and the photo people had uploaded — carried all
 * the way from the server as `photo_path` — was never displayed anywhere.
 * One component now answers the question "what does this person look like?"
 * in one order, everywhere:
 *
 *   1. the photo they uploaded, if they uploaded one
 *   2. the illustration they picked
 *   3. an illustration matching the gender on their profile, if we know it
 *   4. their initial
 *
 * The illustrations are drawn here as inline SVG rather than fetched as
 * images. A meeting of nine, a chat list, a participants panel — these draw
 * dozens of avatars at once, and dozens of image requests to show artwork
 * that never changes is a poor trade against a few kilobytes of markup that
 * is already in the bundle. It also means they work offline, in the installed
 * app, and on a tile that is 24px across.
 */
import { clsx } from 'clsx'

/** Key format: f/m/n (female, male, neutral) + 1-9. */
export type AvatarKey = string

interface Face {
  /** Background disc. */
  bg: string
  skin: string
  hair: string
  /** Clothing across the shoulders. */
  wear: string
  /** Which of the drawn hairstyles to use. */
  style: 'bun' | 'long' | 'bob' | 'wavy' | 'crop' | 'fade' | 'curls' | 'turban' | 'hijab' | 'beard' | 'specs' | 'cap'
}

/*
 * Skin, hair and clothing are varied deliberately rather than recoloured from
 * one figure — the point of offering a choice is that people can find one that
 * looks something like them.
 */
const SKIN = ['#F2C6A0', '#E0A87C', '#C68642', '#8D5524', '#5C3A21', '#FFDCC0']
const HAIR = ['#2B2118', '#4A2F1B', '#6B4423', '#111111', '#8A6A4F', '#3B3B3B']

export const FACES: Record<AvatarKey, Face> = {
  f1: { bg: '#FDE7EF', skin: SKIN[0], hair: HAIR[0], wear: '#E05C8B', style: 'bun' },
  f2: { bg: '#EDE9FE', skin: SKIN[2], hair: HAIR[1], wear: '#7C3AED', style: 'long' },
  f3: { bg: '#DCFCE7', skin: SKIN[5], hair: HAIR[4], wear: '#15803D', style: 'bob' },
  f4: { bg: '#FEF3C7', skin: SKIN[3], hair: HAIR[3], wear: '#B45309', style: 'curls' },
  f5: { bg: '#E0F2FE', skin: SKIN[1], hair: HAIR[2], wear: '#0369A1', style: 'wavy' },
  f6: { bg: '#FAE8FF', skin: SKIN[4], hair: HAIR[0], wear: '#A21CAF', style: 'hijab' },

  m1: { bg: '#DBEAFE', skin: SKIN[0], hair: HAIR[0], wear: '#1D4ED8', style: 'crop' },
  m2: { bg: '#E2E8F0', skin: SKIN[2], hair: HAIR[3], wear: '#334155', style: 'beard' },
  m3: { bg: '#CCFBF1', skin: SKIN[5], hair: HAIR[1], wear: '#0F766E', style: 'specs' },
  m4: { bg: '#FFE4E6', skin: SKIN[3], hair: HAIR[3], wear: '#BE123C', style: 'fade' },
  m5: { bg: '#FEF9C3', skin: SKIN[1], hair: HAIR[2], wear: '#A16207', style: 'turban' },
  m6: { bg: '#EDE9FE', skin: SKIN[4], hair: HAIR[5], wear: '#5B21B6', style: 'cap' },
}

/** Grouped for the picker, in the order they are offered. */
export const AVATAR_GROUPS: { label: string; keys: AvatarKey[] }[] = [
  { label: 'Female', keys: ['f1', 'f2', 'f3', 'f4', 'f5', 'f6'] },
  { label: 'Male', keys: ['m1', 'm2', 'm3', 'm4', 'm5', 'm6'] },
]

/**
 * The illustration to fall back on when somebody has not picked one.
 *
 * Registration asks for gender, so for most people there is a better answer
 * available than a letter. Anything else — not given, or not one of these two
 * — keeps the initial, which belongs to nobody in particular.
 */
export function defaultAvatarFor(gender?: string | null): AvatarKey | null {
  const g = (gender ?? '').trim().toLowerCase()
  if (g.startsWith('f') || g === 'woman') return 'f2'
  if (g.startsWith('m') || g === 'man') return 'm1'
  return null
}

/** One drawn face. `size` is a CSS length; the artwork scales to it. */
export function AvatarArt({ face, className }: { face: Face; className?: string }) {
  const { bg, skin, hair, wear, style } = face
  return (
    <svg viewBox="0 0 64 64" className={className} aria-hidden="true">
      <circle cx="32" cy="32" r="32" fill={bg} />
      {/* Shoulders. Clipped by the disc, so it reads as a portrait crop. */}
      <path d="M6 64c0-13 11.6-20 26-20s26 7 26 20z" fill={wear} />
      <path d="M26 40h12v9c0 3-12 3-12 0z" fill={skin} />

      {/*
        Anything that falls *behind* the face is drawn before it, so the head
        painted on top does the work of cutting the face opening. That is what
        makes a hijab a hijab rather than a large hat: the fabric is one solid
        shape and the face is simply in front of it.
      */}
      {style === 'long' && <path d="M14 30c0-14 8-22 18-22s18 8 18 22v22H14z" fill={hair} />}
      {style === 'wavy' && <path d="M14 30c0-13 8-21 18-21s18 8 18 21v18c-4 3-6-4-6-9H20c0 5-2 12-6 9z" fill={hair} />}
      {style === 'bob' && <path d="M13 25a19 18 0 0 1 38 0v17a3.2 3.2 0 0 1-6.4 0V27a12.6 12.6 0 0 0-25.2 0v15a3.2 3.2 0 0 1-6.4 0z" fill={hair} />}
      {style === 'hijab' && <path d="M11 31c0-14 9-24 21-24s21 10 21 24c0 9-4 16-9 20l3 13H17l3-13c-5-4-9-11-9-20z" fill={wear} />}
      {style === 'curls' && (
        <>
          <ellipse cx="32" cy="21" rx="18" ry="14" fill={hair} />
          {[16, 24, 32, 40, 48].map((cx) => (
            <circle key={cx} cx={cx} cy="13" r="7" fill={hair} />
          ))}
        </>
      )}

      {/* Head. */}
      <ellipse cx="32" cy="27" rx="14" ry="16" fill={skin} />
      {/* Ears, hidden by anything that covers them. */}
      {style !== 'hijab' && style !== 'turban' && style !== 'cap' && (
        <>
          <circle cx="18" cy="29" r="3" fill={skin} />
          <circle cx="46" cy="29" r="3" fill={skin} />
        </>
      )}

      {/* Drawn before the features, so the mouth sits on top of it. */}
      {style === 'beard' && (
        <path d="M19.5 30c0 9 5.5 15 12.5 15s12.5-6 12.5-15c.5 6-1 11-3 13.5-3.5 3.5-15 3.5-19 0-2-2.5-3.5-7.5-3-13.5z" fill={hair} />
      )}

      {style === 'bun' && (
        <>
          <path d="M18 24c0-9 6-14 14-14s14 5 14 14v2c-3-6-8-8-14-8s-11 2-14 8z" fill={hair} />
          <circle cx="32" cy="7" r="5" fill={hair} />
        </>
      )}
      {(style === 'crop' || style === 'specs' || style === 'beard') && (
        <path d="M18 26c0-10 6-15 14-15s14 5 14 15c-2-6-6-8-14-8s-12 2-14 8z" fill={hair} />
      )}
      {style === 'fade' && <path d="M19 24c1-9 7-13 13-13s12 4 13 13c-3-4-7-6-13-6s-10 2-13 6z" fill={hair} />}
      {style === 'turban' && (
        <>
          <path d="M16 27c0-11 7-19 16-19s16 8 16 19c0 2.5-2.5 3-4.5 1-3.5-3.5-6.5-4.5-11.5-4.5s-8 1-11.5 4.5C18.5 30 16 29.5 16 27z" fill={wear} />
          <path d="M19 21c4-4 22-4 26 0" stroke="#000" strokeOpacity="0.18" strokeWidth="1.6" fill="none" strokeLinecap="round" />
        </>
      )}
      {style === 'cap' && (
        <>
          <path d="M18 25a14 14 0 0 1 28 0z" fill={wear} />
          <rect x="16" y="23" width="32" height="4.5" rx="2.2" fill={wear} />
          {/* Peak, off to one side so it reads as a cap and not a helmet. */}
          <path d="M46 23.5h9a2.2 2.2 0 0 1 0 4.4h-9z" fill={wear} />
        </>
      )}

      {/* Eyes, and a mouth that stops short of a grin. */}
      <circle cx="26" cy="27" r="1.9" fill="#1F2937" />
      <circle cx="38" cy="27" r="1.9" fill="#1F2937" />
      <path d="M27 34c2 2 8 2 10 0" stroke="#1F2937" strokeWidth="1.8" strokeLinecap="round" fill="none" />

      {style === 'specs' && (
        <g stroke="#1F2937" strokeWidth="1.6" fill="none">
          <circle cx="26" cy="27" r="5" />
          <circle cx="38" cy="27" r="5" />
          <path d="M31 27h2M21 26l-3 1M43 26l3 1" />
        </g>
      )}
    </svg>
  )
}

/**
 * A person, at any size.
 *
 * `size` is the pixel diameter — passed as a number rather than a class so a
 * caller can size it from a measurement (a video tile) as easily as from the
 * design scale.
 */
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

  const key = avatar ?? defaultAvatarFor(gender)
  const face = key ? FACES[key] : undefined
  if (face) {
    return (
      <span style={style} className={shell} title={name ?? undefined}>
        <AvatarArt face={face} className="h-full w-full" />
      </span>
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
