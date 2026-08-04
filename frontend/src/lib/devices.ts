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
 * Point every peer connection at a different outgoing track and swap it into
 * the local stream, so preview and what peers receive never disagree.
 * Returns the track that was retired (already stopped).
 */
export function swapTrack(
  pcs: Iterable<RTCPeerConnection>,
  local: MediaStream | null,
  next: MediaStreamTrack,
): MediaStreamTrack | null {
  const kind = next.kind
  for (const pc of pcs) {
    const sender = pc.getSenders().find((s) => s.track?.kind === kind)
    sender?.replaceTrack(next).catch(() => undefined)
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
