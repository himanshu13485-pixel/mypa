import { describe, expect, it } from 'vitest'
import { applySendQuality, sendQualityFor } from './devices'

/**
 * In a mesh you send your own picture to everybody separately, so the number
 * that has to fit down one uplink is bitrate x peers. Holding that roughly
 * constant is the whole point — a flat per-connection cap is what made six
 * people need 7.5 Mbps up and ten need 13.5.
 */
const totalUpload = (peers: number) => sendQualityFor(peers).maxBitrate * peers

describe('sendQualityFor', () => {
  it('spends the whole uplink when there is only one other person', () => {
    expect(sendQualityFor(1)).toMatchObject({ maxBitrate: 1_500_000, scaleResolutionDownBy: 1 })
  })

  it('keeps total upload near 2 Mbps across every size mesh claims to serve', () => {
    // A flat bottom rung passed at six and failed at twenty, which is how the
    // original bug worked: fine in testing, 13.5 Mbps in a real full room.
    for (const peers of [1, 2, 3, 4, 5, 6, 8, 10, 12, 16]) {
      expect(totalUpload(peers), `${peers} peers`).toBeLessThanOrEqual(2_500_000)
    }
  })

  it('never asks a phone for more than it can send', () => {
    // Mobile uplink is the binding constraint, not the laptop's.
    expect(totalUpload(6)).toBeLessThanOrEqual(2_500_000)
    expect(totalUpload(10)).toBeLessThanOrEqual(2_500_000)
  })

  it('stops at a floor rather than sending video too poor to be worth it', () => {
    // Past here the budget no longer divides down, and that is the honest
    // signal that mesh is finished — an SFU is the answer, not a lower number,
    // because by then it is the encoding and the connection count that hurt.
    expect(sendQualityFor(40).maxBitrate).toBe(120_000)
    expect(totalUpload(40)).toBeGreaterThan(2_500_000)
  })

  it('steps down rather than up as people arrive', () => {
    const steps = [1, 2, 3, 4, 5, 6, 7, 8, 12].map((n) => sendQualityFor(n).maxBitrate)
    for (let i = 1; i < steps.length; i++) {
      expect(steps[i], `step ${i}`).toBeLessThanOrEqual(steps[i - 1])
    }
  })

  it('scales resolution down alongside bitrate', () => {
    // Bitrate alone just produces a soft 720p; the tiles are small in a full
    // room, so sending fewer pixels is the honest trade.
    expect(sendQualityFor(8).scaleResolutionDownBy).toBeGreaterThan(sendQualityFor(1).scaleResolutionDownBy)
  })

  it('handles an empty room without dividing by anything', () => {
    expect(sendQualityFor(0).maxBitrate).toBeGreaterThan(0)
  })
})

/** A sender that records what it was told, standing in for a real one. */
function fakePc(kinds: string[]) {
  const senders = kinds.map((kind) => {
    let params: RTCRtpSendParameters = { encodings: [], transactionId: '', codecs: [], headerExtensions: [], rtcp: {} }
    return {
      track: { kind },
      getParameters: () => params,
      setParameters: (next: RTCRtpSendParameters) => {
        params = next
        return Promise.resolve()
      },
      applied: () => params.encodings?.[0],
    }
  })

  return { pc: { getSenders: () => senders } as unknown as RTCPeerConnection, senders }
}

describe('applySendQuality', () => {
  it('configures every video sender it is given', () => {
    const a = fakePc(['video'])
    const b = fakePc(['video'])

    applySendQuality([a.pc, b.pc], 8)

    // 2 Mbps of budget shared eight ways.
    expect(a.senders[0].applied()).toMatchObject({ maxBitrate: 250_000, scaleResolutionDownBy: 3 })
    expect(b.senders[0].applied()).toMatchObject({ maxBitrate: 250_000, scaleResolutionDownBy: 3 })
  })

  it('leaves audio alone', () => {
    // Capping voice is how you make a meeting unusable while it still looks fine.
    const { pc, senders } = fakePc(['audio'])
    applySendQuality([pc], 8)
    expect(senders[0].applied()).toBeUndefined()
  })

  it('creates an encoding when the connection has not negotiated one yet', () => {
    const { pc, senders } = fakePc(['video'])
    applySendQuality([pc], 2)
    expect(senders[0].applied()).toBeDefined()
  })
})
