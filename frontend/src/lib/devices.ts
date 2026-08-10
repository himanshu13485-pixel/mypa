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
