import { useEffect, useState } from 'react'

/**
 * Per-peer connection health, read straight off RTCPeerConnection.getStats().
 *
 * Zoom shows bars; we grade the same three numbers it grades on — packet loss,
 * round trip and jitter — and take the worst of them, because one bad
 * dimension is enough to make a call unpleasant.
 */

export type Quality = 'good' | 'fair' | 'poor' | 'unknown'

export interface PeerStats {
  quality: Quality
  /** Inbound packet loss as a percentage, 0-100. */
  lossPct: number
  /** Round-trip time in milliseconds. */
  rttMs: number
  jitterMs: number
}

const EMPTY: PeerStats = { quality: 'unknown', lossPct: 0, rttMs: 0, jitterMs: 0 }

function grade(lossPct: number, rttMs: number, jitterMs: number): Quality {
  if (lossPct >= 8 || rttMs >= 500 || jitterMs >= 100) return 'poor'
  if (lossPct >= 3 || rttMs >= 250 || jitterMs >= 40) return 'fair'
  return 'good'
}

/**
 * getStats gives cumulative counters, so loss has to be measured between two
 * samples — a call that dropped packets in its first minute shouldn't still
 * look bad half an hour later.
 */
export async function readPeerStats(
  pc: RTCPeerConnection,
  previous?: { packets: number; lost: number },
): Promise<{ stats: PeerStats; counters: { packets: number; lost: number } }> {
  let packets = 0
  let lost = 0
  let rttMs = 0
  let jitterMs = 0

  try {
    const report = await pc.getStats()
    report.forEach((entry) => {
      if (entry.type === 'inbound-rtp' && !entry.isRemote) {
        packets += (entry.packetsReceived as number) ?? 0
        lost += (entry.packetsLost as number) ?? 0
        jitterMs = Math.max(jitterMs, ((entry.jitter as number) ?? 0) * 1000)
      }
      if (entry.type === 'candidate-pair' && entry.state === 'succeeded' && entry.nominated) {
        rttMs = Math.max(rttMs, ((entry.currentRoundTripTime as number) ?? 0) * 1000)
      }
    })
  } catch {
    return { stats: EMPTY, counters: previous ?? { packets: 0, lost: 0 } }
  }

  const counters = { packets, lost }
  if (!previous) {
    // First sample has no window to compare against; report the link timings
    // we do have and wait for the next tick to judge loss.
    return { stats: { quality: 'unknown', lossPct: 0, rttMs, jitterMs }, counters }
  }

  const deltaPackets = Math.max(0, packets - previous.packets)
  const deltaLost = Math.max(0, lost - previous.lost)
  const total = deltaPackets + deltaLost
  const lossPct = total > 0 ? (deltaLost / total) * 100 : 0

  return { stats: { quality: grade(lossPct, rttMs, jitterMs), lossPct, rttMs, jitterMs }, counters }
}

/** Samples every peer connection every few seconds. Keyed by peer uuid. */
export function usePeerQuality(pcs: () => Map<string, RTCPeerConnection>, enabled: boolean, intervalMs = 4000) {
  const [stats, setStats] = useState<Record<string, PeerStats>>({})

  useEffect(() => {
    if (!enabled) return
    const counters = new Map<string, { packets: number; lost: number }>()
    let cancelled = false

    const tick = async () => {
      const next: Record<string, PeerStats> = {}
      for (const [uuid, pc] of pcs()) {
        const { stats: s, counters: c } = await readPeerStats(pc, counters.get(uuid))
        counters.set(uuid, c)
        next[uuid] = s
      }
      if (!cancelled) setStats(next)
    }

    void tick()
    const timer = setInterval(tick, intervalMs)
    return () => {
      cancelled = true
      clearInterval(timer)
    }
  }, [pcs, enabled, intervalMs])

  return stats
}
