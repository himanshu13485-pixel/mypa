import { describe, expect, it } from 'vitest'
import { mergeForHandover, readyToRetire } from './handover'

/*
 * A MediaStream stand-in. jsdom has no WebRTC, and none of this looks inside
 * the object — it only cares whether a tile has one.
 */
const stream = (id: string) => ({ id } as unknown as MediaStream)

const tile = (uuid: string, s: MediaStream | null = null) => ({ uuid, stream: s })

/** Nobody left on the mesh: what a meeting that started on the SFU looks like. */
const none = () => false

describe('readyToRetire', () => {
  it('names only the people whose media is already coming from the server', () => {
    expect(readyToRetire([tile('a', stream('a')), tile('b'), tile('c', stream('c'))]))
      .toEqual(['a', 'c'])
  })

  it('leaves a participant who has arrived but is not publishing on the mesh', () => {
    // Camera off, or still negotiating. Dropping the mesh connection now would
    // blank a tile the mesh is carrying perfectly well.
    expect(readyToRetire([tile('a')])).toEqual([])
  })
})

describe('mergeForHandover', () => {
  it('never blanks a tile the mesh is still carrying', () => {
    // The whole reason both paths run at once. If this can return a tile with
    // no stream where the mesh had one, somebody sees black mid-meeting.
    const current = [tile('a', stream('mesh-a')), tile('b', stream('mesh-b'))]
    // b has reached the SFU and is not publishing yet; a has not arrived at all.
    const merged = mergeForHandover(current, [tile('b')], () => true)

    expect(merged.map((p) => p.uuid).sort()).toEqual(['a', 'b'])
    expect(merged.every((p) => p.stream)).toBe(true)
  })

  it('takes the SFU stream once it is actually flowing', () => {
    const merged = mergeForHandover(
      [tile('a', stream('mesh-a'))],
      [tile('a', stream('sfu-a'))],
      () => true,
    )
    expect(merged).toHaveLength(1)
    expect((merged[0].stream as unknown as { id: string }).id).toBe('sfu-a')
  })

  it('keeps somebody who is still only on the mesh', () => {
    // They have not migrated yet. Their tile is not stale, it is early.
    const merged = mergeForHandover([tile('slow', stream('mesh'))], [], (u) => u === 'slow')
    expect(merged.map((p) => p.uuid)).toEqual(['slow'])
  })

  it('drops somebody who is on neither', () => {
    // Now they really have left, and a tile that outlives the person is worse
    // than one that appears a beat late.
    expect(mergeForHandover([tile('gone', stream('mesh'))], [], none)).toEqual([])
  })

  it('adds a newcomer who only ever existed on the SFU', () => {
    const merged = mergeForHandover([], [tile('new', stream('sfu'))], none)
    expect(merged.map((p) => p.uuid)).toEqual(['new'])
  })

  it('carries the tile\'s other fields across the handover', () => {
    // Avatar, mic and camera flags, pinned state — everything the room worked
    // out about this person while they were on the mesh. Losing them would
    // make the move visible even with the video intact.
    const current = [{ ...tile('a', stream('mesh')), avatar: 'f3', micOff: true }]
    const [merged] = mergeForHandover(
      current,
      // Typed as the richer tile, because that is what the room passes: the
      // SFU's own entry carries only a uuid, a name and a stream.
      [tile('a', stream('sfu')) as typeof current[number]],
      () => true,
    )

    expect(merged.avatar).toBe('f3')
    expect(merged.micOff).toBe(true)
  })

  it('reduces to "the server is the room" once the mesh is gone', () => {
    // Which is the ordinary SFU meeting, and the reason this function runs
    // there too rather than only during the rarer handover.
    const list = [tile('a', stream('a')), tile('b', stream('b'))]
    expect(mergeForHandover(list, list, none)).toEqual(list)
    expect(mergeForHandover(list, [tile('a', stream('a'))], none).map((p) => p.uuid)).toEqual(['a'])
  })
})
