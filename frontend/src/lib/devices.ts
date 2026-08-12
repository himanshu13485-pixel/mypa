import { useCallback, useEffect, useState } from 'react'

/**
 * Camera / microphone / speaker plumbing for the meeting room.
 *
 * Three jobs: list what the machine has, remember what the user picked last
 * time, and swap a live device mid-call without renegotiating the peer
 * connections (replaceTrack does that for us).
 */

export interface DeviceOption {
  deviceId: string
  label: string
  kind: MediaDeviceKind
}

export interface DeviceChoice {
  cameraId?: string
  micId?: string
  speakerId?: string
  /** Which way a phone camera points; drives the reverse button. */
  facing?: 'user' | 'environment'
  /** Flip your own preview like a mirror. Local only — never sent to peers. */
  mirror?: boolean
}

const STORE_KEY = 'mypa-meeting-devices'

export function loadDeviceChoice(): DeviceChoice {
  try {
    return JSON.parse(localStorage.getItem(STORE_KEY) ?? '{}') as DeviceChoice
  } catch {
    return {}
  }
}

export function saveDeviceChoice(patch: DeviceChoice): DeviceChoice {
  const next = { ...loadDeviceChoice(), ...patch }
  try {
    localStorage.setItem(STORE_KEY, JSON.stringify(next))
  } catch {
    /* private mode — the choice just won't survive a reload */
  }
  return next
}

/** Chrome only fills in device labels once a permission has been granted. */
export async function listDevices(): Promise<DeviceOption[]> {
  if (!navigator.mediaDevices?.enumerateDevices) return []
  const all = await navigator.mediaDevices.enumerateDevices()
  return all
    .filter((d) => ['videoinput', 'audioinput', 'audiooutput'].includes(d.kind))
    .map((d, i) => ({
      deviceId: d.deviceId,
      kind: d.kind,
      label: d.label || `${d.kind === 'videoinput' ? 'Camera' : d.kind === 'audioinput' ? 'Microphone' : 'Speaker'} ${i + 1}`,
    }))
}

/**
 * getUserMedia, with the two retreats a real machine needs.
 *
 * A camera on Windows is usually exclusive: while one page holds it, a second
 * request for the same device fails outright. And stopping a track returns
 * long before the operating system has actually let the device go, so the very
 * next request lands in that gap. Both surface as NotReadableError, worded by
 * the browser as "could not start video source" — which reads to everyone as
 * "some other app has my camera" even when the other app was this page a
 * moment ago.
 *
 * So: try again a few times, then, if the trouble is the exact camera asked
 * for, drop the constraint and take whatever camera there is. A saved
 * deviceId goes stale whenever the device list changes — unplug a webcam, use
 * a different dock — and `exact` turns that into a hard failure. That is why
 * pressing the flip-camera button "fixed" it: flipping asks for a different
 * device.
 *
 * Throws only if every retreat fails, which is then genuinely worth telling
 * somebody about.
 */
export async function openMedia(want: MediaStreamConstraints): Promise<MediaStream> {
  const retryable = ['NotReadableError', 'AbortError']
  let lastErr: unknown

  for (const wait of [0, 250, 500]) {
    if (wait) await new Promise((r) => setTimeout(r, wait))
    try {
      return await navigator.mediaDevices.getUserMedia(want)
    } catch (err) {
      lastErr = err
      if (!retryable.includes((err as Error)?.name)) break
    }
  }

  // Last resort: the same request without pinning a particular device.
  const loosened = (c: boolean | MediaTrackConstraints | undefined) =>
    (c && typeof c === 'object' && 'deviceId' in c ? { ...c, deviceId: undefined } : c)
  const relaxed: MediaStreamConstraints = {
    audio: loosened(want.audio as MediaTrackConstraints),
    video: loosened(want.video as MediaTrackConstraints),
  }

  if (JSON.stringify(relaxed) !== JSON.stringify(want)) {
    try {
      return await navigator.mediaDevices.getUserMedia(relaxed)
    } catch { /* fall through to the original error, which is the honest one */ }
  }

  throw lastErr
}

/** Live list that refreshes when something is plugged in or unplugged. */
export function useDevices(enabled = true) {
  const [devices, setDevices] = useState<DeviceOption[]>([])

  const refresh = useCallback(() => {
    listDevices().then(setDevices).catch(() => undefined)
  }, [])

  useEffect(() => {
    if (!enabled) return
    refresh()
    navigator.mediaDevices?.addEventListener?.('devicechange', refresh)
    return () => navigator.mediaDevices?.removeEventListener?.('devicechange', refresh)
  }, [enabled, refresh])

  return {
    cameras: devices.filter((d) => d.kind === 'videoinput'),
    mics: devices.filter((d) => d.kind === 'audioinput'),
    speakers: devices.filter((d) => d.kind === 'audiooutput'),
    refresh,
  }
}

/** More than one camera is the precondition for the reverse button. */
export async function hasMultipleCameras(): Promise<boolean> {
  return (await listDevices()).filter((d) => d.kind === 'videoinput').length > 1
}

export const VIDEO_CONSTRAINTS: MediaTrackConstraints = {
  width: { ideal: 1280 },
  height: { ideal: 720 },
  frameRate: { ideal: 30 },
}

/**
 * How much video to send each peer, given how many there are.
 *
 * In a mesh everybody sends their own picture to everybody else, so a flat
 * per-connection cap means total upload grows with the room: at 1.5 Mbps
 * apiece, six people needed 7.5 Mbps up and ten needed 13.5 — past what most
 * home connections give and far past a phone on mobile data. The room did not
 * fail politely either; it degraded for everyone at once.
 *
 * Stepping the cap down as people arrive holds the total near 2 Mbps whatever
 * the size, which is the number that has to fit down one uplink. Resolution
 * comes down with it — bitrate alone just makes a soft 720p, and the tiles are
 * small in a full room anyway.
 *
 * Above about twelve this stops being enough and the answer is an SFU, where
 * each person sends one stream and the server does the copying.
 */
export interface SendQuality {
  maxBitrate: number
  /** 1 = full capture resolution, 2 = half width and height, and so on. */
  scaleResolutionDownBy: number
  /** For logging and the network panel — not sent anywhere. */
  label: string
}

/** What one uplink is asked to carry in total, whatever the room size. */
const UPLOAD_BUDGET = 2_000_000

/**
 * Below this, video is worse than no video — blocky enough to be a distraction
 * while still costing the bandwidth. Past the point where the budget divides
 * down to it, the mesh is simply out of road: the fix is an SFU, not a smaller
 * number, because by then it is the encoding and the connection count hurting
 * rather than the bitrate.
 */
const FLOOR = 120_000

/** Nobody needs more than this, even alone with one other person. */
const CEILING = 1_500_000

export function sendQualityFor(peerCount: number): SendQuality {
  const peers = Math.max(1, peerCount)

  /*
   * Divide the budget, all the way up.
   *
   * This started as fixed rungs — 700k up to three people, 350k up to six —
   * which made the total sawtooth: 1.4 Mbps at two, 2.1 at three, back to 1.4
   * at four. The peaks were the top of each rung and meant nothing, and the
   * rungs were also worse than dividing at most sizes (700k where division
   * gives 1000k at two people, 350k where it gives 500k at four). They added
   * an edge to reason about in exchange for lower quality.
   */
  // floor, not round: the budget is a ceiling, so rounding up puts the total
  // over it — by one bit at three people, which is harmless, but a rule that
  // can exceed its own limit is one you cannot then rely on.
  const maxBitrate = Math.min(CEILING, Math.max(FLOOR, Math.floor(UPLOAD_BUDGET / peers)))

  /*
   * Resolution still steps, where bitrate does not.
   *
   * A scale factor that slid a little every time somebody joined would have
   * the encoder re-keying on each arrival for a change nobody can see. Three
   * steps are enough, and they line up with how large the tiles actually are
   * by then.
   */
  const scale = peers <= 1 ? 1 : peers <= 3 ? 1.5 : peers <= 6 ? 2 : 3
  const label = scale === 1 ? '720p' : scale === 1.5 ? '480p' : scale === 2 ? '360p' : '240p'

  return { maxBitrate, scaleResolutionDownBy: scale, label }
}

/**
 * Apply that to every outgoing video sender.
 *
 * Called whenever the room changes size, not only when a connection is made —
 * the fifth person arriving has to quieten down the four who were already
 * talking, or only their own stream is sized for the room they are in.
 */
export function applySendQuality(pcs: Iterable<RTCPeerConnection>, peerCount: number): SendQuality {
  const quality = sendQualityFor(peerCount)

  for (const pc of pcs) {
    for (const sender of pc.getSenders()) {
      if (sender.track?.kind !== 'video') continue
      const params = sender.getParameters()
      // getParameters can come back with no encodings before the first
      // negotiation; setting one is how you get something to configure.
      params.encodings = params.encodings?.length ? params.encodings : [{}]
      params.encodings[0].maxBitrate = quality.maxBitrate
      params.encodings[0].scaleResolutionDownBy = quality.scaleResolutionDownBy
      sender.setParameters(params).catch(() => undefined)
    }
  }

  return quality
}

export const AUDIO_CONSTRAINTS: MediaTrackConstraints = {
  echoCancellation: true,
  noiseSuppression: true,
  autoGainControl: true,
}

export async function openCamera(opts: { deviceId?: string; facing?: 'user' | 'environment' }): Promise<MediaStreamTrack> {
  // deviceId is exact and wins; facingMode is the phone-friendly fallback and
  // stays "ideal" because desktop webcams report no facing mode at all.
  const video: MediaTrackConstraints = { ...VIDEO_CONSTRAINTS }
  if (opts.deviceId) video.deviceId = { exact: opts.deviceId }
  else if (opts.facing) video.facingMode = { ideal: opts.facing }

  const stream = await navigator.mediaDevices.getUserMedia({ video })
  return stream.getVideoTracks()[0]
}

export async function openMic(deviceId?: string): Promise<MediaStreamTrack> {
  const audio: MediaTrackConstraints = { ...AUDIO_CONSTRAINTS }
  if (deviceId) audio.deviceId = { exact: deviceId }
  const stream = await navigator.mediaDevices.getUserMedia({ audio })
  return stream.getAudioTracks()[0]
}

/**
 * The sender carrying one kind of media on this connection — including one
 * that is currently holding nothing.
 *
 * Found through the transceiver rather than the sender's current track. A
 * sender is empty whenever the camera was off at join or has since been
 * switched off and released, and it has no track to read a kind from; looking
 * only at sender.track matched nothing, so the camera coming back on, a screen
 * share starting, or a background being applied all quietly reached nobody.
 * The transceiver knows what it is for either way.
 */
export function senderFor(pc: RTCPeerConnection, kind: 'audio' | 'video'): RTCRtpSender | undefined {
  return pc.getTransceivers()
    .find((t) => (t.sender.track?.kind ?? t.receiver.track?.kind) === kind)?.sender
    ?? pc.getSenders().find((s) => s.track?.kind === kind)
}

/**
 * Point every peer connection at a different outgoing track and swap it into
 * the local stream, so preview and what peers receive never disagree.
 * Returns the track that was retired (already stopped).
 */
export function swapTrack(
  pcs: Iterable<RTCPeerConnection>,
  local: MediaStream | null,
  next: MediaStreamTrack,
): MediaStreamTrack | null {
  // MediaStreamTrack.kind is typed as a bare string; it is only ever one of
  // the two, and senderFor asks for the narrower type.
  const kind = next.kind === 'audio' ? 'audio' : 'video'
  for (const pc of pcs) {
    senderFor(pc, kind)?.replaceTrack(next).catch(() => undefined)
  }

  let retired: MediaStreamTrack | null = null
  if (local) {
    const old = kind === 'video' ? local.getVideoTracks()[0] : local.getAudioTracks()[0]
    if (old && old !== next) {
      // Carry the mute state over — flipping the camera must not silently
      // switch it back on.
      next.enabled = old.enabled
      local.removeTrack(old)
      old.stop()
      retired = old
    }
    local.addTrack(next)
  }
  return retired
}

/**
 * Which camera to open next when the reverse button is pressed. Phones expose
 * a facingMode we can just invert; anything else (laptop + USB webcam) cycles
 * through the camera list instead.
 */
export function nextCamera(
  cameras: DeviceOption[],
  current: { deviceId?: string; facing?: 'user' | 'environment' },
): { deviceId?: string; facing?: 'user' | 'environment' } {
  if (cameras.length > 1 && current.deviceId) {
    const i = cameras.findIndex((c) => c.deviceId === current.deviceId)
    const next = cameras[(i + 1) % cameras.length]
    return { deviceId: next.deviceId }
  }
  return { facing: current.facing === 'environment' ? 'user' : 'environment' }
}

/**
 * Route audio to a chosen speaker. Only Chromium implements setSinkId, so
 * this reports back whether it actually took effect.
 */
export async function applySpeaker(deviceId: string, root: ParentNode = document): Promise<boolean> {
  const elements = [...root.querySelectorAll('audio, video')] as (HTMLMediaElement & {
    setSinkId?: (id: string) => Promise<void>
  })[]
  if (!elements.length || typeof elements[0].setSinkId !== 'function') return false

  await Promise.all(elements.map((el) => el.setSinkId?.(deviceId).catch(() => undefined)))
  return true
}

export function speakerSelectionSupported(): boolean {
  return typeof (HTMLMediaElement.prototype as { setSinkId?: unknown }).setSinkId === 'function'
}

/**
 * Which speaker to use when nobody has chosen one.
 *
 * The browser's own default is "whatever the system says", which on a phone
 * is the earpiece — right for a call held to your ear, wrong for a meeting
 * on a desk, and wrong for both when a headset is paired and being ignored.
 *
 * A headset always wins: pairing one is itself the instruction. Below that
 * the two differ on purpose. A call is a conversation held to the head, so
 * the earpiece comes next and the loudspeaker last. A meeting is watched at
 * arm's length, so the loudspeaker comes next and the earpiece last.
 *
 * Matched on labels, which is all the Web Audio API offers — and only when
 * permission has been granted, since labels are empty before that. An unknown
 * device sorts mid-table rather than last: something unrecognised is more
 * likely a real output than the earpiece is to be the right one.
 */
/** Named for the two, since AudioContext is the browser's own — and is used
    a few lines below by testSpeaker, which my shadowing quietly broke. */
export type ListeningContext = 'call' | 'meeting'

export function preferredSpeaker(devices: DeviceOption[], context: ListeningContext): string | null {
  const rank = (label: string): number => {
    const l = label.toLowerCase()
    if (/bluetooth|headset|airpod|buds|headphone|wireless/.test(l)) return 0
    const earpiece = /earpiece|receiver|handset|phone speaker/.test(l)
    const loud = /speakerphone|loudspeaker|^speaker\b|speaker$/.test(l)
    if (context === 'call') {
      if (earpiece) return 1
      if (loud) return 3
    } else {
      if (loud) return 1
      if (earpiece) return 3
    }

    return 2
  }

  const best = [...devices]
    .filter((d) => d.kind === 'audiooutput' && d.deviceId)
    .sort((a, b) => rank(a.label) - rank(b.label))[0]

  return best?.deviceId ?? null
}

/**
 * Can this browser share a screen at all?
 *
 * The room used to decide this from the window width, which is a guess about
 * the device rather than a question about the browser — and the two disagreed:
 * the toolbar hid the button on a phone while the overflow sheet offered it
 * anyway, so the only place a phone could reach Share was the one place it
 * could never work. Asking the API directly is right in both directions. A
 * narrow desktop window keeps its button, and any browser that gains screen
 * capture gets one the day it does, with nothing here to update.
 *
 * getDisplayMedia also needs a secure context, so an http:// origin has it
 * undefined and is correctly reported as unable.
 */
export function screenShareSupported(): boolean {
  return typeof navigator.mediaDevices?.getDisplayMedia === 'function'
}

/**
 * Why a share attempt failed, or null if it did not really fail.
 *
 * Dismissing the picker rejects exactly like being refused, and both arrive as
 * NotAllowedError — so that one stays silent, because a toast saying "you
 * cancelled" every time somebody changes their mind is noise. Everything else
 * is a genuine fault and used to be swallowed by a bare catch, which is how
 * pressing Share came to do nothing at all with nothing to show for it.
 */
export function shareFailureMessage(err: unknown): string | null {
  const name = (err as { name?: string } | null)?.name

  if (name === 'NotAllowedError' || name === 'AbortError') return null
  if (name === 'NotFoundError') return 'No screen or window was available to share.'
  if (name === 'NotReadableError') return 'Your system would not hand over the screen — another app may be capturing it.'
  if (!screenShareSupported()) {
    return 'This browser cannot share a screen. On a phone that is a browser limitation, not a setting — '
      + 'join from a computer to share, or point the back camera at what you want people to see.'
  }

  return 'Could not start sharing your screen.'
}

/**
 * Play a short tone out of one speaker, so "did that work?" has an answer that
 * does not involve asking the room to say something.
 *
 * Picking an output device is otherwise silent by definition: nothing happens
 * until somebody speaks, and if the wrong one was chosen you find out by
 * missing what they said.
 */
export async function testSpeaker(deviceId?: string): Promise<void> {
  let ctx: AudioContext
  try {
    ctx = new AudioContext()
  } catch {
    return
  }

  const osc = ctx.createOscillator()
  const gain = ctx.createGain()
  osc.frequency.value = 660
  // Straight to full volume clicks; a short ramp at each end does not.
  gain.gain.setValueAtTime(0, ctx.currentTime)
  gain.gain.linearRampToValueAtTime(0.12, ctx.currentTime + 0.02)
  gain.gain.setValueAtTime(0.12, ctx.currentTime + 0.28)
  gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.35)
  osc.connect(gain)

  // An AudioContext plays wherever the system says; only a media element can
  // be pointed at a chosen output, so the tone goes through one.
  const dest = ctx.createMediaStreamDestination()
  gain.connect(dest)
  const el = new Audio()
  el.srcObject = dest.stream
  if (deviceId) {
    await (el as HTMLAudioElement & { setSinkId?: (id: string) => Promise<void> })
      .setSinkId?.(deviceId).catch(() => undefined)
  }

  try {
    await el.play()
    osc.start()
    await new Promise((r) => setTimeout(r, 400))
  } catch {
    /* autoplay policy — the click that opened the menu should have covered it */
  } finally {
    osc.stop()
    el.pause()
    el.srcObject = null
    await ctx.close().catch(() => undefined)
  }
}

/**
 * Mic level 0..1, sampled ~20x/sec. Used by the pre-join meter so people can
 * see their microphone works before they walk into the room.
 */
export function useMicLevel(stream: MediaStream | null): number {
  const [level, setLevel] = useState(0)

  useEffect(() => {
    if (!stream?.getAudioTracks().length) {
      setLevel(0)
      return
    }
    let ctx: AudioContext
    try {
      ctx = new AudioContext()
    } catch {
      return
    }
    const analyser = ctx.createAnalyser()
    analyser.fftSize = 256
    ctx.createMediaStreamSource(new MediaStream(stream.getAudioTracks())).connect(analyser)
    const data = new Uint8Array(new ArrayBuffer(analyser.frequencyBinCount))

    const timer = setInterval(() => {
      analyser.getByteFrequencyData(data)
      let sum = 0
      for (let i = 0; i < data.length; i++) sum += data[i]
      setLevel(Math.min(1, sum / data.length / 70))
    }, 50)

    return () => {
      clearInterval(timer)
      ctx.close().catch(() => undefined)
    }
  }, [stream])

  return level
}
