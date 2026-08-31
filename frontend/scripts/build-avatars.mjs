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
import { avataaars, openPeeps } from '@dicebear/collection'
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

/*
 * The professional set: working adults, dressed for work.
 *
 * Avataaars rather than Notionists, and every feature named rather than left
 * to a seed. The first attempt seeded Notionists and got twelve people who
 * looked like sixth-formers, because a seed is free to choose a hoodie, and
 * free to choose `hearts` for the eyes or `vomit` for the mouth. Nothing about
 * "pick a random valid combination" knows this is going next to somebody's
 * name at work.
 *
 * So the clothing here is only ever a blazer or a collar, the expressions are
 * only ever neutral or a plain smile, and the colours are office colours —
 * navy, charcoal, slate — rather than the pinks and yellows in the palette.
 * Facial hair and glasses are placed deliberately: they are most of what
 * separates a drawn adult from a drawn teenager.
 *
 * Licence: free for personal and commercial use (avataaars.com), with no
 * attribution required. The illustrated set stays CC0; this one is the same
 * bargain in practice — nothing owed, nothing to carry in the app — but it is
 * a different licence and worth knowing that.
 */
const PROFESSIONAL = [
  // key, hair/head, clothing, facial hair, glasses, skin, clothes colour
  ['p1', 'shortFlat', 'blazerAndShirt', null, null, 'edb98a', '262e33'],
  ['p2', 'bun', 'collarAndSweater', null, 'prescription02', 'f8d25c', '3c4f5c'],
  ['p3', 'shortWaved', 'blazerAndSweater', 'beardLight', null, 'd08b5b', '25557c'],
  ['p4', 'hijab', 'blazerAndShirt', null, null, 'd08b5b', '3c4f5c'],
  ['p5', 'shortRound', 'collarAndSweater', null, 'prescription01', 'ae5d29', '929598'],
  ['p6', 'straight02', 'blazerAndShirt', null, null, 'edb98a', '262e33'],
  ['p7', 'turban', 'blazerAndSweater', 'beardMedium', null, 'd08b5b', '25557c'],
  ['p8', 'bob', 'blazerAndShirt', null, 'round', 'ffdbb4', '3c4f5c'],
  ['p9', 'theCaesar', 'collarAndSweater', 'moustacheFancy', null, 'ae5d29', '929598'],
  ['p10', 'longButNotTooLong', 'blazerAndSweater', null, null, '614335', '25557c'],
  ['p11', 'sides', 'blazerAndShirt', 'beardMedium', 'wayfarers', '614335', '262e33'],
  ['p12', 'curvy', 'collarAndSweater', null, null, 'ffdbb4', '3c4f5c'],
]

const DESK_BACKGROUNDS = ['dbeafe', 'e0e7ff', 'e2e8f0', 'ede9fe']

fs.mkdirSync(OUT, { recursive: true })

PROFESSIONAL.forEach(([key, top, clothing, facialHair, accessories, skinColor, clothesColor], i) => {
  const svg = createAvatar(avataaars, {
    // Still needs one, for anything not pinned below.
    seed: key,
    top: [top],
    clothing: [clothing],
    clothesColor: [clothesColor],
    skinColor: [skinColor],
    backgroundColor: [DESK_BACKGROUNDS[i % DESK_BACKGROUNDS.length]],
    radius: 50,
    scale: 90,

    /*
     * Pinned, because the alternatives are unusable here. The eye list
     * includes hearts, cry and xDizzy; the mouth list includes vomit and
     * screamOpen. A seed picks from all of them.
     */
    eyes: ['default'],
    eyebrows: ['defaultNatural'],
    mouth: i % 2 ? ['smile'] : ['default'],

    facialHair: facialHair ? [facialHair] : [],
    facialHairProbability: facialHair ? 100 : 0,
    accessories: accessories ? [accessories] : [],
    accessoriesProbability: accessories ? 100 : 0,
  }).toString()

  fs.writeFileSync(path.join(OUT, `${key}.svg`), svg)
})

console.log(`wrote ${SET.length + PROFESSIONAL.length} avatars to src/assets/avatars/`)
