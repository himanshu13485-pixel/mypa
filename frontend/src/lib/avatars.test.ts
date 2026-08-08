import { describe, expect, it } from 'vitest'
import { AVATAR_GROUPS, FACES, defaultAvatarFor, photoUrl } from './avatars'

describe('the avatar set', () => {
  it('offers every key it advertises, and draws every key it offers', () => {
    const offered = AVATAR_GROUPS.flatMap((g) => g.keys)
    for (const key of offered) {
      expect(FACES[key], `${key} is offered in the picker but not drawn`).toBeDefined()
    }
    expect(new Set(offered).size).toBe(offered.length)
    // Anything drawn but never offered is dead artwork.
    expect(Object.keys(FACES).sort()).toEqual([...offered].sort())
  })

  it('gives every face its own look rather than one figure recoloured', () => {
    const looks = Object.values(FACES).map((f) => `${f.style}|${f.skin}|${f.hair}`)
    expect(new Set(looks).size).toBe(looks.length)
  })
})

describe('defaultAvatarFor', () => {
  it('answers for the gender the profile actually stores', () => {
    for (const male of ['male', 'Male', 'M', 'man']) {
      expect(defaultAvatarFor(male)?.startsWith('m')).toBe(true)
    }
    for (const female of ['female', 'Female', 'F', 'woman']) {
      expect(defaultAvatarFor(female)?.startsWith('f')).toBe(true)
    }
  })

  it('declines to guess when it does not know', () => {
    // An initial belongs to nobody in particular, which is the point:
    // picking a face for someone who did not say is worse than a letter.
    for (const unknown of [null, undefined, '', '   ', 'other', 'non-binary', 'prefer not to say']) {
      expect(defaultAvatarFor(unknown)).toBeNull()
    }
  })
})

describe('photoUrl', () => {
  it('puts a stored path under /storage where the server serves it', () => {
    expect(photoUrl('profile-photos/abc.jpg')).toBe('/storage/profile-photos/abc.jpg')
    expect(photoUrl('/profile-photos/abc.jpg')).toBe('/storage/profile-photos/abc.jpg')
  })

  it('leaves an absolute URL alone, so a move to a CDN needs no change here', () => {
    expect(photoUrl('https://cdn.example/a.jpg')).toBe('https://cdn.example/a.jpg')
    expect(photoUrl('http://cdn.example/a.jpg')).toBe('http://cdn.example/a.jpg')
  })
})
