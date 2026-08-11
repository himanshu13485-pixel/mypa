/**
 * Draw the twelve picker avatars, once, into src/assets/avatars/.
 *
 *   node scripts/build-avatars.mjs
 *
 * They were hand-drawn SVG before — twelve faces assembled from a handful of
 * skin, hair and style parameters — and they looked it. These come from Open
 * Peeps by Pablo Stanley, via DiceBear.
 *
 * Open Peeps is CC0 1.0: public domain, no attribution required, nothing owed
 * for commercial use. That is why it was chosen over the styles that look
 * comparable but are CC BY, which would have meant carrying a credit in the
 * app for ever.
 *
 * Generated rather than fetched, and committed rather than generated at build
 * time: @dicebear/* stays a devDependency that never reaches a user, the files
 * are reviewable in a diff, and nothing about rendering somebody's avatar
 * depends on a package still existing in two years.
 *
 * Re-running is safe and rewrites all twelve. Keep the keys — f1..f6, m1..m6
 * are stored on profiles, so renaming one silently blanks that person's
 * avatar.
 */
import { createAvatar } from '@dicebear/core'
import { openPeeps } from '@dicebear/collection'
import fs from 'node:fs'
import path from 'node:path'

const OUT = path.join(import.meta.dirname, '..', 'src', 'assets', 'avatars')

/*
 * Twelve deliberate looks, not twelve random seeds.
 *
 * The picker groups them and people choose one to represent themselves, so
 * each has to be a distinct silhouette rather than whatever a seed produced.
 * Distinct at 28px is the bar: that is the size in a conversation list, and a
 * face that is only recognisable at 72px is not doing the job.
 */
const SET = [
  ['f1', 'long', '#ffd5dc', { face: ['smile'] }],
  ['f2', 'bun', '#c0aede', { face: ['calm'] }],
  ['f3', 'longCurly', '#d1d4f9', { face: ['smileBig'] }],
  ['f4', 'hijab', '#ffdfbf', { face: ['calm'] }],
  ['f5', 'bangs', '#b6e3f4', { face: ['cheeky'], accessories: ['glasses2'], accessoriesProbability: 100 }],
  ['f6', 'bantuKnots', '#d1fae5', { face: ['smile'] }],

  ['m1', 'short1', '#ffd5dc', { face: ['calm'] }],
  ['m2', 'shaved2', '#c0aede', { face: ['serious'], facialHair: ['full2'], facialHairProbability: 100 }],
  ['m3', 'medium1', '#d1d4f9', { face: ['smile'], accessories: ['glasses'], accessoriesProbability: 100 }],
  ['m4', 'flatTop', '#ffdfbf', { face: ['smile'] }],
  ['m5', 'turban', '#b6e3f4', { face: ['calm'] }],
  ['m6', 'hatHip', '#d1fae5', { face: ['cheeky'] }],
]

fs.mkdirSync(OUT, { recursive: true })

for (const [key, head, background, extra] of SET) {
  const svg = createAvatar(openPeeps, {
    seed: key,
    head: [head],
    backgroundColor: [background.replace('#', '')],
    radius: 50,
    // A touch of room inside the circle: at 100 the hair clips the edge.
    scale: 90,
    // A profile picture is not a pandemic photo, and blushes read as dirt at
    // list size.
    maskProbability: 0,
    blushesProbability: 0,
    ...extra,
  }).toString()

  fs.writeFileSync(path.join(OUT, `${key}.svg`), svg)
}

console.log(`wrote ${SET.length} avatars to src/assets/avatars/`)
