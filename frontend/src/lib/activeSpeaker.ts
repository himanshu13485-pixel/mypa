import { useEffect, useRef, useState } from 'react'

/**
 * Detects who is currently speaking by measuring audio levels of every
 * stream (~4x/sec, with hysteresis so the highlight doesn't flicker).
 * Returns the uuid of the loudest speaker, or null when everyone is quiet.
 */
export function useActiveSpeaker(entries: { uuid: string; stream: MediaStream | null }[]): string | null {
  const [active, setActive] = useState<string | null>(null)
  const analysersRef = useRef<Map<string, { analyser: AnalyserNode; data: Uint8Array<ArrayBuffer> }>>(new Map())
  const ctxRef = useRef<AudioContext | null>(null)
  const activeRef = useRef<string | null>(null)
  activeRef.current = active

  const key = entries.map((e) => `${e.uuid}:${e.stream?.id ?? '-'}`).join('|')

  useEffect(() => {
    if (!entries.some((e) => e.stream)) return
    const ctx = ctxRef.current ?? new AudioContext()
    ctxRef.current = ctx
    const analysers = analysersRef.current

    for (const { uuid, stream } of entries) {
      if (!stream || analysers.has(uuid) || !stream.getAudioTracks().length) continue
      try {
        const analyser = ctx.createAnalyser()
        analyser.fftSize = 256
        ctx.createMediaStreamSource(new MediaStream(stream.getAudioTracks())).connect(analyser)
        analysers.set(uuid, { analyser, data: new Uint8Array(new ArrayBuffer(analyser.frequencyBinCount)) })
      } catch {
        /* stream ended */
      }
    }
    // drop analysers for gone entries
    const uuids = new Set(entries.map((e) => e.uuid))
    for (const uuid of [...analysers.keys()]) {
      if (!uuids.has(uuid)) analysers.delete(uuid)
    }

    const timer = setInterval(() => {
      let bestUuid: string | null = null
      let bestLevel = 0
      let currentLevel = 0
      analysers.forEach(({ analyser, data }, uuid) => {
        analyser.getByteFrequencyData(data)
        let sum = 0
        for (let i = 0; i < data.length; i++) sum += data[i]
        const level = sum / data.length
        if (uuid === activeRef.current) currentLevel = level
        if (level > bestLevel) {
          bestLevel = level
          bestUuid = uuid
        }
      })
      // quiet room -> no highlight; keep the current speaker unless someone
      // is clearly louder (hysteresis against flicker)
      if (bestLevel < 12) {
        if (activeRef.current !== null) setActive(null)
      } else if (bestUuid !== activeRef.current && bestLevel > currentLevel * 1.4) {
        setActive(bestUuid)
      }
    }, 250)

    return () => clearInterval(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  useEffect(() => {
    return () => {
      ctxRef.current?.close().catch(() => undefined)
      ctxRef.current = null
    }
  }, [])

  return active
}
