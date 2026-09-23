import { beforeEach, describe, expect, it } from 'vitest'
import { attachmentOpened, useOpenedAttachments } from './attachmentsOpened'

/**
 * What the receiver has actually taken down.
 *
 * The rule this holds up: whoever sent a file has it already, and everybody
 * else has nothing until they ask for it - so nothing can be forwarded,
 * replied to or reacted to before it has arrived on this side.
 */
describe('attachments this person has taken down', () => {
  beforeEach(() => useOpenedAttachments.setState({ ids: new Set<number>() }))

  it('starts with nothing: a thread just opened has fetched nothing', () => {
    expect(attachmentOpened(1)).toBe(false)
  })

  it('remembers what was asked for', () => {
    useOpenedAttachments.getState().markOpened(7)

    expect(attachmentOpened(7)).toBe(true)
    expect(attachmentOpened(8)).toBe(false)
  })

  it('hands back a new set, so a bubble watching it wakes up', () => {
    const before = useOpenedAttachments.getState().ids
    useOpenedAttachments.getState().markOpened(3)

    expect(useOpenedAttachments.getState().ids).not.toBe(before)
  })

  it('says nothing new when the same file is opened twice', () => {
    useOpenedAttachments.getState().markOpened(3)
    const after = useOpenedAttachments.getState().ids
    useOpenedAttachments.getState().markOpened(3)

    expect(useOpenedAttachments.getState().ids).toBe(after)
  })

  /**
   * The gate the thread applies, written out here because it is the rule
   * rather than the plumbing: my own message never waits, and somebody
   * else's waits until every file on it has come down.
   */
  const notTakenDown = (message: { is_own: boolean; attachments?: { id: number }[] }) =>
    !message.is_own && (message.attachments ?? []).some((a) => !attachmentOpened(a.id))

  it('lets my own pictures through - I chose them off my own disk', () => {
    expect(notTakenDown({ is_own: true, attachments: [{ id: 11 }, { id: 12 }] })).toBe(false)
  })

  it('holds somebody else’s back until each file has come down', () => {
    const theirs = { is_own: false, attachments: [{ id: 21 }, { id: 22 }] }
    expect(notTakenDown(theirs)).toBe(true)

    useOpenedAttachments.getState().markOpened(21)
    expect(notTakenDown(theirs)).toBe(true)

    useOpenedAttachments.getState().markOpened(22)
    expect(notTakenDown(theirs)).toBe(false)
  })

  it('never holds back a message that carries no files', () => {
    expect(notTakenDown({ is_own: false })).toBe(false)
    expect(notTakenDown({ is_own: false, attachments: [] })).toBe(false)
  })
})
