import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import {
  Circle, Expand, Maximize2, Mic, MicOff, Minimize2, MonitorUp, MoreHorizontal, Phone, PhoneOff, Pin, PinOff,
  Square, SwitchCamera, UserPlus, Users, Video, VideoOff, X,
} from 'lucide-react'
import { clsx } from 'clsx'
import { calls } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { PickUserModal } from './UserSuggest'
import { useToast } from './Toast'
import { Button } from './ui'
import type { CallSignalPayload } from '../types'
import { startRingtone } from '../lib/alerts'
import { useActiveSpeaker } from '../lib/activeSpeaker'
import { startCompositeRecording, type CompositeRecorder } from '../lib/recorder'
import { createEffectTrack, createSharePipeline, type BlurPipeline } from '../lib/videoFx'
import BackgroundPicker, { type BackgroundChoice } from './BackgroundPicker'
import { normalizeSdp } from '../lib/sdp'
import { VIDEO_FIT, useGalleryLayout, useSelfView } from '../lib/videoLayout'
import { isPhoneViewport, useIsPhone, useLandscapePhone } from '../lib/useMediaQuery'
import { loadDeviceChoice, nextCamera, openCamera, saveDeviceChoice, swapTrack, useDevices } from '../lib/devices'
import { Avatar } from '../lib/avatars'

interface ActiveCall {
  uuid: string
  type: 'audio' | 'video'
  direction: 'outgoing' | 'incoming'
  callerUuid?: string
  peerName: string
  isGroup: boolean
  status: 'ringing' | 'connecting' | 'ongoing'
  startedAt?: number
}

interface RemotePeer {
  uuid: string
  name: string
  /** From the call heartbeat, so a dark tile still shows a face. */
  avatar?: string | null
  stream: MediaStream | null
  micOff?: boolean
  camOff?: boolean
  sharing?: boolean
  /** ICE state — shown on the tile so a stuck connection is visible. */
  conn?: string
}

interface CallContextValue {
  startCall: (conversationUuid: string, type: 'audio' | 'video', peerName: string) => Promise<void>
  /** Walk into a call that is already running (from the Calls list). */
  joinCall: (uuid: string, type: 'audio' | 'video', label: string) => Promise<void>
  /** Hang up / cancel the current call (used by the voice assistant too). */
  endCall: () => Promise<void>
  activeCall: ActiveCall | null
}

const CallContext = createContext<CallContextValue>({
  startCall: async () => {},
  joinCall: async () => {},
  endCall: async () => {},
  activeCall: null,
})

export const useCalls = () => useContext(CallContext)

/** Attaches a MediaStream to a video/audio element via ref callback. */
function RemoteTile({ peer, video, active, className, style, cover, hideName, onContextMenu, onDoubleClick }: {
  peer: RemotePeer
  video: boolean
  active?: boolean
  className?: string
  style?: React.CSSProperties
  /**
   * Fill the tile and crop, rather than fit and letterbox.
   *
   * Only ever true when the tile *is* the screen — a phone call, where the
   * choice is between cropping a little off the sides of the picture and
   * showing thick black bars above and below a face. Every phone calling app
   * crops. In a grid, where the tile is not the screen, cropping would cut
   * people's heads off, so it stays off there.
   */
  cover?: boolean
  hideName?: boolean
  onContextMenu?: (e: React.MouseEvent) => void
  onDoubleClick?: (e: React.MouseEvent) => void
}) {
  const attach = (el: HTMLVideoElement | HTMLAudioElement | null) => {
    if (el && el.srcObject !== peer.stream) {
      el.srcObject = peer.stream
      // srcObject set after mount does not always start playback by itself.
      el.play().catch((err) => console.warn('[call] audio playback blocked', err))
    }
  }
  if (!video) {
    return (
      <span className="flex items-center gap-1 text-[10px] text-slate-300">
        <audio ref={attach} autoPlay />
        {peer.micOff && <MicOff className="size-3 text-red-500" />}
      </span>
    )
  }
  return (
    <div
      onContextMenu={onContextMenu}
      onDoubleClick={onDoubleClick}
      style={style}
      className={clsx('relative min-h-0 overflow-hidden rounded-lg bg-slate-900', active && 'ring-2 ring-emerald-400', className)}
    >
      {/* Fit, never crop — a tile is rarely the camera's own shape. */}
      <video ref={attach} autoPlay playsInline className={cover ? 'h-full w-full bg-black object-cover' : VIDEO_FIT} />
      {peer.conn && !['connected', 'completed'].includes(peer.conn) && (
        <span
          className={
            'absolute left-1 top-1 rounded px-1.5 py-0.5 text-[10px] font-semibold ' +
            (peer.conn === 'failed' ? 'bg-red-600 text-white' : 'bg-amber-400 text-black')
          }
        >
          {peer.conn === 'failed' ? 'reconnecting…' : `connecting… ${peer.conn}`}
        </span>
      )}
      {peer.camOff && (
        <div className="absolute inset-0 flex items-center justify-center bg-slate-900">
          <Avatar name={peer.name} avatar={peer.avatar} size={44} />
        </div>
      )}
      {!hideName && (
        <span className="absolute bottom-1 left-1 flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white">
          {peer.name}
          {peer.micOff && <MicOff className="size-3 text-red-500" />}
          {peer.camOff && <VideoOff className="size-3 text-red-500" />}
        </span>
      )}
    </div>
  )
}

/** A round control that floats over video, as phone calling apps draw them. */
function CircleButton({ on, danger, label, onClick, children }: {
  /** Lit — the effect is active. Off is the translucent resting state. */
  on?: boolean
  danger?: boolean
  label: string
  onClick: () => void
  children: ReactNode
}) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      onClick={onClick}
      className={clsx(
        'flex size-12 shrink-0 items-center justify-center rounded-full backdrop-blur transition-colors',
        danger
          ? 'bg-red-600 text-white hover:bg-red-700'
          : on
            ? 'bg-white text-slate-900'
            : 'bg-white/20 text-white hover:bg-white/30',
      )}
    >
      {children}
    </button>
  )
}

/**
 * Mesh calling: every participant keeps a direct RTCPeerConnection to every
 * other participant. The rule that keeps signalling deterministic: whoever
 * JOINS the call sends the offers to everyone already in it.
 */
export function CallProvider({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  const { toast, toastError } = useToast()
  const [showInvite, setShowInvite] = useState(false)
  const [activeCall, setActiveCall] = useState<ActiveCall | null>(null)
  const [incoming, setIncoming] = useState<CallSignalPayload | null>(null)
  const [remotePeers, setRemotePeers] = useState<RemotePeer[]>([])
  const [muted, setMuted] = useState(false)
  const [cameraOff, setCameraOff] = useState(false)
  const [elapsed, setElapsed] = useState(0)
  const [recording, setRecording] = useState(false)
  const [peerRecording, setPeerRecording] = useState<string | null>(null)
  const [recPending, setRecPending] = useState(false)
  const [recRequest, setRecRequest] = useState<{ uuid: string; name: string } | null>(null)
  const startRecRef = useRef<() => void>(() => undefined)
  const [sharing, setSharing] = useState(false)
  /** Right-click pin. Local to this browser — nobody else's view changes. */
  const [pinned, setPinned] = useState<string | null>(null)
  const [tileMenu, setTileMenu] = useState<{ uuid: string; name: string; x: number; y: number } | null>(null)
  const displayTrackRef = useRef<MediaStreamTrack | null>(null)
  /** Canvas compositor drawing the camera onto the shared screen. */
  const sharePipeRef = useRef<BlurPipeline | null>(null)
  const [isFs, setIsFs] = useState(false)
  /**
   * Compact corner panel, or a big centred window. Remembered between calls.
   *
   * A phone starts expanded whatever it remembers: the corner panel is a 320px
   * box, which on a 390px screen is not a corner at all — it covers most of the
   * page it is meant to be floating over, and sits on top of the bottom tab bar
   * while doing it. There is nothing to keep an eye on behind it either way.
   */
  const [expanded, setExpanded] = useState(
    () => localStorage.getItem('mypa-call-expanded') === '1' || isPhoneViewport(),
  )
  const [bgLabel, setBgLabel] = useState('none')
  /** The last background chosen, so a camera swap can rebuild it. */
  const bgChoiceRef = useRef<BackgroundChoice | null>(null)
  const [blurBusy, setBlurBusy] = useState(false)
  const myMediaRef = useRef({ mic: true, cam: true })
  const recorderRef = useRef<CompositeRecorder | null>(null)
  const blurRef = useRef<BlurPipeline | null>(null)
  const cameraTrackRef = useRef<MediaStreamTrack | null>(null)
  /** Which camera is open, so the flip button knows what to swap to. */
  const [deviceChoice, setDeviceChoice] = useState(loadDeviceChoice)
  const [flipping, setFlipping] = useState(false)
  const callBodyRef = useRef<HTMLDivElement>(null)

  /** The whole panel — video AND controls. Fullscreen used to target only the
   *  video, which is why the controls disappeared the moment you expanded. */
  const panelRef = useRef<HTMLDivElement>(null)
  const restartTimersRef = useRef<Map<string, number>>(new Map())

  const peersRef = useRef<Map<string, RTCPeerConnection>>(new Map())
  // ICE candidates that arrived before their peer connection / remote
  // description was ready — dropped candidates are the classic cause of
  // one-way or missing audio in mesh calls.
  const pendingIceRef = useRef<Map<string, RTCIceCandidateInit[]>>(new Map())
  const iceServersRef = useRef<RTCIceServer[] | null>(null)
  const localStreamRef = useRef<MediaStream | null>(null)
  /* Shared with meetings: survives the re-mounts that used to blank the
     self-view — swapping between the video and audio-only bodies replaces
     the <video>, and a plain ref never re-attached the stream. */
  const { show: showSelf, attach: attachSelf } = useSelfView()
  const callRef = useRef<ActiveCall | null>(null)
  callRef.current = activeCall

  /** Apply any ICE candidates that arrived early for this peer. */
  const flushPendingIce = useCallback((peerUuid: string) => {
    const pc = peersRef.current.get(peerUuid)
    const pending = pendingIceRef.current.get(peerUuid)
    if (!pc || !pc.remoteDescription || !pending?.length) return
    pendingIceRef.current.delete(peerUuid)
    for (const candidate of pending) {
      pc.addIceCandidate(candidate).catch((err) => console.warn('[call] flush ICE failed', err))
    }
  }, [])

  /** Flip to 'ongoing' and start the timer (idempotent). */
  const markLive = useCallback(() => {
    setActiveCall((c) =>
      c && c.status !== 'ongoing' ? { ...c, status: 'ongoing', startedAt: c.startedAt ?? Date.now() } : c,
    )
  }, [])

  const activeSpeaker = useActiveSpeaker([
    { uuid: 'me', stream: localStreamRef.current },
    ...remotePeers.map((p) => ({ uuid: p.uuid, stream: p.stream })),
  ])

  useEffect(() => {
    const onFs = () => setIsFs(!!document.fullscreenElement)
    document.addEventListener('fullscreenchange', onFs)
    return () => document.removeEventListener('fullscreenchange', onFs)
  }, [])

  const cleanup = useCallback(() => {
    recorderRef.current?.stop()
    recorderRef.current = null
    setRecording(false)
    setPeerRecording(null)
    setRecPending(false)
    setRecRequest(null)
    blurRef.current?.stop()
    blurRef.current = null
    setBgLabel('none')
    sharePipeRef.current?.stop()
    sharePipeRef.current = null
    displayTrackRef.current?.stop()
    displayTrackRef.current = null
    setSharing(false)
    if (document.fullscreenElement) document.exitFullscreen().catch(() => undefined)
    myMediaRef.current = { mic: true, cam: true }
    peersRef.current.forEach((pc) => pc.close())
    peersRef.current.clear()
    pendingIceRef.current.clear()
    localStreamRef.current?.getTracks().forEach((t) => t.stop())
    localStreamRef.current = null
    setRemotePeers([])
    setActiveCall(null)
    setIncoming(null)
    setMuted(false)
    setCameraOff(false)
    setElapsed(0)
  }, [])

  /** Grab mic/camera once per call; shared by all peer connections. */
  const ensureLocalStream = useCallback(async (type: 'audio' | 'video') => {
    if (localStreamRef.current) return localStreamRef.current
    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true },
        video: type === 'video'
          ? { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30 } }
          : false,
      })
    } catch (err) {
      if (type === 'video') {
        // Camera busy elsewhere: continue the call audio-only.
        console.warn('[call] camera unavailable, audio-only fallback', err)
        stream = await navigator.mediaDevices.getUserMedia({ audio: true })
        setCameraOff(true)
      } else {
        throw err
      }
    }
    localStreamRef.current = stream
    cameraTrackRef.current = stream.getVideoTracks()[0] ?? null
    showSelf(stream)
    return stream
  }, [showSelf])

  /**
   * Rebuild a broken media path without rebuilding the call.
   *
   * A dropped ICE route used to be permanent: the tile just stayed black and
   * nothing retried, which is how a call ends up with some people seeing each
   * other and others not. Only one side may restart or the two offers collide,
   * so the peer with the lower uuid does it — arbitrary but consistent.
   */
  const restartIce = useCallback(async (peerUuid: string) => {
    const pc = peersRef.current.get(peerUuid)
    const uuid = callRef.current?.uuid
    if (!pc || !uuid || !user?.uuid || user.uuid > peerUuid) return
    try {
      const offer = await pc.createOffer({ iceRestart: true })
      await pc.setLocalDescription(offer)
      await calls.signal(uuid, 'offer', { sdp: offer.sdp, type: offer.type }, peerUuid)
      console.info('[call] ice restart sent to', peerUuid.slice(0, 8))
    } catch (err) {
      console.warn('[call] ice restart failed', err)
    }
  }, [user?.uuid])

  /** One RTCPeerConnection per remote participant. */
  const createPeer = useCallback(
    async (callUuid: string, peerUuid: string, peerName: string, type: 'audio' | 'video') => {
      const existing = peersRef.current.get(peerUuid)
      if (existing) return existing

      if (!iceServersRef.current) {
        iceServersRef.current = (await calls.config()).iceServers
      }
      const pc = new RTCPeerConnection({ iceServers: iceServersRef.current })
      peersRef.current.set(peerUuid, pc)

      const stream = await ensureLocalStream(type)
      stream.getTracks().forEach((track) => pc.addTrack(track, stream))
      pc.getSenders().forEach((sender) => {
        if (sender.track?.kind !== 'video') return
        const params = sender.getParameters()
        params.encodings = params.encodings?.length ? params.encodings : [{}]
        params.encodings[0].maxBitrate = 1_500_000
        sender.setParameters(params).catch(() => undefined)
      })

      setRemotePeers((peers) =>
        peers.some((p) => p.uuid === peerUuid) ? peers : [...peers, { uuid: peerUuid, name: peerName, stream: null }],
      )

      pc.ontrack = (event) => {
        const [remote] = event.streams
        setRemotePeers((peers) => peers.map((p) => (p.uuid === peerUuid ? { ...p, stream: remote } : p)))
        markLive()
      }

      // Belt and braces: several independent "we are live" triggers, because a
      // single missed signalling message must never leave the timer stuck.
      pc.onconnectionstatechange = () => {
        console.info('[call] peer', peerUuid.slice(0, 8), 'connection:', pc.connectionState)
        if (pc.connectionState === 'connected') markLive()
      }
      pc.oniceconnectionstatechange = () => {
        const state = pc.iceConnectionState
        console.info('[call] peer', peerUuid.slice(0, 8), 'ice:', state)
        setRemotePeers((peers) => peers.map((p) => (p.uuid === peerUuid ? { ...p, conn: state } : p)))
        if (state === 'connected' || state === 'completed') markLive()

        const timers = restartTimersRef.current
        if (state === 'failed') {
          void restartIce(peerUuid)
        } else if (state === 'disconnected') {
          // A brief blip often heals itself; give it a moment before retrying.
          if (!timers.has(peerUuid)) {
            timers.set(peerUuid, window.setTimeout(() => {
              timers.delete(peerUuid)
              if (peersRef.current.get(peerUuid)?.iceConnectionState === 'disconnected') void restartIce(peerUuid)
            }, 4000))
          }
        } else {
          const t = timers.get(peerUuid)
          if (t) {
            clearTimeout(t)
            timers.delete(peerUuid)
          }
        }
      }

      pc.onicecandidate = (event) => {
        if (event.candidate) {
          calls.signal(callUuid, 'ice', { candidate: event.candidate.toJSON() }, peerUuid).catch(() => undefined)
        }
      }

      return pc
    },
    [ensureLocalStream, markLive, restartIce],
  )

  const removePeer = useCallback((peerUuid: string) => {
    peersRef.current.get(peerUuid)?.close()
    peersRef.current.delete(peerUuid)
    const t = restartTimersRef.current.get(peerUuid)
    if (t) {
      clearTimeout(t)
      restartTimersRef.current.delete(peerUuid)
    }
    setRemotePeers((peers) => peers.filter((p) => p.uuid !== peerUuid))
  }, [])

  const startCall = useCallback(
    async (conversationUuid: string, type: 'audio' | 'video', peerName: string) => {
      if (callRef.current) return
      try {
        const call = await calls.initiate(conversationUuid, type)
        setActiveCall({
          uuid: call.uuid,
          type,
          direction: 'outgoing',
          peerName,
          isGroup: !!call.is_group,
          status: 'ringing',
        })
        await ensureLocalStream(type)
      } catch (err) {
        cleanup()
        alert(err instanceof Error && 'response' in err
          ? ((err as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Could not start the call.')
          : 'Could not start the call.')
      }
    },
    [ensureLocalStream, cleanup],
  )

  const acceptIncoming = useCallback(async () => {
    if (!incoming) return
    const signal = incoming
    setIncoming(null)
    setActiveCall({
      uuid: signal.call_uuid,
      type: signal.call_type,
      direction: 'incoming',
      callerUuid: signal.from_uuid,
      peerName: signal.from_name ?? 'Caller',
      isGroup: false, // corrected below from the respond payload
      status: 'connecting',
    })
    try {
      await ensureLocalStream(signal.call_type)
      const info = await calls.respond(signal.call_uuid, 'accept')
      setActiveCall((c) =>
        c ? { ...c, isGroup: !!info.is_group, peerName: info.group_name ?? c.peerName } : c,
      )

      // Joiner sends an offer to everyone already in the call.
      const joined = info.joined_peers ?? [{ uuid: signal.from_uuid, name: signal.from_name ?? 'Caller' }]
      for (const peer of joined) {
        const pc = await createPeer(signal.call_uuid, peer.uuid, peer.name, signal.call_type)
        const offer = await pc.createOffer()
        await pc.setLocalDescription(offer)
        await calls.signal(signal.call_uuid, 'offer', { sdp: offer.sdp, type: offer.type }, peer.uuid)
      }
    } catch {
      cleanup()
    }
  }, [incoming, ensureLocalStream, createPeer, cleanup])

  /**
   * Join a call that is already in progress. Same handshake as accepting a
   * ringing one — accept, then offer to everyone already inside — which is
   * why a late joiner works at all; there was simply no way to trigger it.
   */
  const joinCall = useCallback(async (uuid: string, type: 'audio' | 'video', label: string) => {
    if (callRef.current) return
    setActiveCall({ uuid, type, direction: 'incoming', peerName: label, isGroup: false, status: 'connecting' })
    try {
      await ensureLocalStream(type)
      const info = await calls.respond(uuid, 'accept')
      setActiveCall((c) => (c ? { ...c, isGroup: !!info.is_group, peerName: info.group_name ?? label } : c))

      for (const peer of info.joined_peers ?? []) {
        const pc = await createPeer(uuid, peer.uuid, peer.name, type)
        const offer = await pc.createOffer()
        await pc.setLocalDescription(offer)
        await calls.signal(uuid, 'offer', { sdp: offer.sdp, type: offer.type }, peer.uuid)
      }
    } catch (err) {
      cleanup()
      toastError(errorMessage(err))
    }
  }, [ensureLocalStream, createPeer, cleanup, toastError])

  const declineIncoming = useCallback(async () => {
    if (!incoming) return
    const uuid = incoming.call_uuid
    setIncoming(null)
    calls.respond(uuid, 'decline').catch(() => undefined)
  }, [incoming])

  const startRecordingNow = () => {
    const uuid = callRef.current?.uuid
    if (!uuid || !callBodyRef.current) return
    recorderRef.current = startCompositeRecording({
      container: callBodyRef.current,
      audioStreams: () => [
        ...(localStreamRef.current ? [localStreamRef.current] : []),
        ...remotePeers.map((p) => p.stream).filter((s): s is MediaStream => !!s),
      ],
      fileLabel: 'netvork-call',
      onStop: () => setRecording(false),
    })
    setRecording(true)
    peersRef.current.forEach((_, peerUuid) => calls.signal(uuid, 'record', { on: true }, peerUuid).catch(() => undefined))
  }
  startRecRef.current = startRecordingNow

  const toggleRecord = () => {
    const uuid = callRef.current?.uuid
    if (!uuid) return
    if (recording) {
      recorderRef.current?.stop()
      recorderRef.current = null
      setRecording(false)
      peersRef.current.forEach((_, peerUuid) => calls.signal(uuid, 'record', { on: false }, peerUuid).catch(() => undefined))
      return
    }
    // Only the caller (call host) records freely; others ask first.
    const call = callRef.current
    if (call && call.direction !== 'outgoing') {
      if (recPending || !call.callerUuid) return
      setRecPending(true)
      calls.signal(uuid, 'rec-request', { ask: 1 }, call.callerUuid).catch(() => setRecPending(false))
      return
    }
    startRecordingNow()
  }

  /**
   * Front to back on a phone, or the next webcam on a laptop.
   *
   * Meetings have had this since the device work; calls never did, so anyone
   * on a phone was stuck with whichever camera happened to open — no way to
   * show the person you are talking to what you are looking at.
   *
   * replaceTrack swaps the outgoing video without renegotiating, so nobody
   * else sees so much as a flicker. A background effect is built on top of the
   * old track, so it has to be rebuilt on the new one or the peers keep
   * receiving the camera that was just closed.
   */
  const flipCamera = async () => {
    if (flipping || sharing) return
    setFlipping(true)
    try {
      const target = nextCamera(cameras, { deviceId: deviceChoice.cameraId, facing: deviceChoice.facing })
      const track = await openCamera(target)
      cameraTrackRef.current = track
      track.enabled = !cameraOff
      swapTrack(peersRef.current.values(), localStreamRef.current, track)

      const chosen = bgChoiceRef.current
      if (blurRef.current && chosen?.effect) {
        // Rebuilt from the same choice the picker last applied.
        const rebuilt = await createEffectTrack(track, chosen.effect)
        blurRef.current?.stop()
        blurRef.current = rebuilt
        rebuilt.track.enabled = !cameraOff
        peersRef.current.forEach((pc) => {
          const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
          sender?.replaceTrack(rebuilt.track).catch(() => undefined)
        })
        showSelf(new MediaStream([rebuilt.track]))
      } else {
        showSelf(localStreamRef.current)
      }

      setDeviceChoice(saveDeviceChoice(target))
    } catch (err) {
      toastError('Could not switch camera — it may be in use by another app.')
      console.warn('[call] camera flip failed', err)
    } finally {
      setFlipping(false)
    }
  }

  const applyBackground = async (choice: BackgroundChoice) => {
    if (blurBusy) return
    const restore = () => {
      const camera = cameraTrackRef.current
      peersRef.current.forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
        if (camera) sender?.replaceTrack(camera).catch(() => undefined)
      })
      showSelf(localStreamRef.current)
      blurRef.current?.stop()
      blurRef.current = null
    }
    bgChoiceRef.current = choice
    if (!choice.effect) {
      restore()
      setBgLabel('none')
      return
    }
    if (!cameraTrackRef.current) return
    setBlurBusy(true)
    try {
      const pipeline = await createEffectTrack(cameraTrackRef.current, choice.effect)
      blurRef.current?.stop()
      blurRef.current = pipeline
      pipeline.track.enabled = !cameraOff
      peersRef.current.forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
        sender?.replaceTrack(pipeline.track).catch(() => undefined)
      })
      showSelf(new MediaStream([pipeline.track]))
      setBgLabel(choice.label)
    } catch (err) {
      alert('Background effect could not start (it needs internet for the model on first use).')
      console.warn('[call] background failed', err)
    } finally {
      setBlurBusy(false)
    }
  }

  const hangUp = useCallback(async () => {
    const uuid = callRef.current?.uuid
    cleanup()
    if (uuid) calls.end(uuid).catch(() => undefined)
  }, [cleanup])

  // Personal channel: call signals
  useEffect(() => {
    if (!user?.uuid) return
    const echo = getEcho()
    if (!echo) return

    const channel = echo.private(`user.${user.uuid}`)

    channel.listen('.call.signal', async (signal: CallSignalPayload) => {
      const call = callRef.current

      switch (signal.signal) {
        case 'ring':
          if (!call) setIncoming(signal)
          break
        case 'accept': {
          // Someone joined; they will send us an offer. Just reflect status.
          setActiveCall((c) => (c && c.status === 'ringing' ? { ...c, status: 'connecting' } : c))
          break
        }
        case 'offer': {
          if (!call || call.uuid !== signal.call_uuid) return
          try {
            const pc = await createPeer(
              signal.call_uuid,
              signal.from_uuid,
              signal.from_name ?? 'Participant',
              call.type,
            )
            await pc.setRemoteDescription({ type: 'offer', sdp: normalizeSdp(signal.payload.sdp as string) })
            flushPendingIce(signal.from_uuid)
            markLive()
            const answer = await pc.createAnswer()
            await pc.setLocalDescription(answer)
            await calls.signal(signal.call_uuid, 'answer', { sdp: answer.sdp, type: answer.type }, signal.from_uuid)
          } catch (err) {
            console.warn('[call] handling offer failed', err)
          }
          break
        }
        case 'answer': {
          const pc = peersRef.current.get(signal.from_uuid)
          if (!pc) return
          try {
            await pc.setRemoteDescription({ type: 'answer', sdp: normalizeSdp(signal.payload.sdp as string) })
            flushPendingIce(signal.from_uuid)
          } catch (err) {
            console.warn('[call] applying answer failed', err)
          }
          markLive()
          break
        }
        case 'share':
          setRemotePeers((p) => p.map((x) => (x.uuid === signal.from_uuid ? { ...x, sharing: !!signal.payload.on } : x)))
          break
        case 'record':
          setPeerRecording(signal.payload.on ? (signal.from_name ?? 'Someone') : null)
          break
        case 'media':
          setRemotePeers((p) => p.map((x) => (x.uuid === signal.from_uuid
            ? { ...x, micOff: signal.payload.mic === false, camOff: signal.payload.cam === false }
            : x)))
          break
        case 'rec-request':
          setRecRequest({ uuid: signal.from_uuid, name: signal.from_name ?? 'Someone' })
          break
        case 'rec-allow':
          setRecPending(false)
          startRecRef.current()
          break
        case 'rec-deny':
          setRecPending(false)
          alert('The call host did not allow recording.')
          break
        case 'ice': {
          const candidate = signal.payload.candidate as RTCIceCandidateInit | undefined
          if (!candidate) return
          const pc = peersRef.current.get(signal.from_uuid)
          if (pc && pc.remoteDescription) {
            pc.addIceCandidate(candidate).catch((err) => console.warn('[call] add ICE failed', err))
          } else {
            // Peer connection not ready yet — hold the candidate, it is
            // replayed right after the remote description is applied.
            const queue = pendingIceRef.current.get(signal.from_uuid) ?? []
            queue.push(candidate)
            pendingIceRef.current.set(signal.from_uuid, queue)
          }
          break
        }
        case 'peer-left':
          removePeer((signal.payload.left_uuid as string) ?? signal.from_uuid)
          break
        case 'decline':
          // In a group call a single decline doesn't end anything.
          if (call?.isGroup) return
          cleanup()
          break
        case 'end':
          cleanup()
          break
      }
    })

    return () => {
      echo.leave(`user.${user.uuid}`)
    }
  }, [user?.uuid, cleanup, createPeer, removePeer, markLive, flushPendingIce])

  // Ringtone for incoming calls; ring-back while our outgoing call rings.
  useEffect(() => {
    if (incoming) return startRingtone('incoming')
  }, [incoming])
  useEffect(() => {
    if (activeCall?.status === 'ringing' && activeCall.direction === 'outgoing') {
      return startRingtone('outgoing')
    }
  }, [activeCall?.status, activeCall?.direction])

  /**
   * Presence ping. The server sweeps anyone who stops sending this, so a
   * closed tab or a dead connection stops leaving a permanent frozen tile on
   * everyone else's screen, and a call nobody is left in ends by itself.
   */
  useEffect(() => {
    if (activeCall?.status !== 'ongoing') return
    const uuid = activeCall.uuid
    let stop = false

    const tick = async () => {
      try {
        const beat = await calls.heartbeat(uuid)
        if (stop) return
        if (beat.status !== 'ongoing' && beat.status !== 'ringing') cleanup()
        /*
         * The heartbeat is the authority on who is called what.
         *
         * A tile takes its name from whichever signal introduced that peer,
         * and for a long time every signal in a call was labelled with the
         * name of whoever started it, so a room of three wore one name. The
         * server fills that field correctly now; this puts right anything that
         * drifted anyway, within one beat, without a message having to arrive.
         */
        if (beat.participants?.length) {
          const roster = new Map(beat.participants.map((p) => [p.uuid, p]))
          setRemotePeers((ps) =>
            ps.some((x) => {
              const row = roster.get(x.uuid)
              return row && (row.name !== x.name || (row.avatar ?? null) !== (x.avatar ?? null))
            })
              ? ps.map((x) => {
                  const row = roster.get(x.uuid)
                  return row ? { ...x, name: row.name, avatar: row.avatar ?? null } : x
                })
              : ps,
          )
        }
      } catch {
        /* one missed beat is fine — the grace window is three of them */
      }
    }

    void tick()
    const timer = setInterval(tick, 15_000)
    return () => {
      stop = true
      clearInterval(timer)
    }
  }, [activeCall?.status, activeCall?.uuid, cleanup])

  /**
   * Closing the tab never runs a normal request, so the hang-up has to go out
   * with keepalive — otherwise the browser cancels it and we become the ghost
   * the reaper cleans up 45 seconds later.
   */
  useEffect(() => {
    const bye = () => {
      const uuid = callRef.current?.uuid
      if (!uuid) return
      const token = useAuthStore.getState().token
      fetch(`/api/v1/calls/${uuid}/end`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        keepalive: true,
      }).catch(() => undefined)
    }
    window.addEventListener('pagehide', bye)
    return () => window.removeEventListener('pagehide', bye)
  }, [])

  // Call duration ticker
  useEffect(() => {
    if (activeCall?.status !== 'ongoing') return
    const timer = setInterval(() => {
      if (callRef.current?.startedAt) {
        setElapsed(Math.floor((Date.now() - callRef.current.startedAt) / 1000))
      }
    }, 1000)
    return () => clearInterval(timer)
  }, [activeCall?.status])

  const stopShare = () => {
    const camera = blurRef.current?.track ?? cameraTrackRef.current
    peersRef.current.forEach((pc) => {
      const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
      if (camera) sender?.replaceTrack(camera).catch(() => undefined)
    })
    sharePipeRef.current?.stop() // before its inputs go
    sharePipeRef.current = null
    displayTrackRef.current?.stop()
    displayTrackRef.current = null
    showSelf(localStreamRef.current)
    setSharing(false)
    const uuid = callRef.current?.uuid
    if (uuid) peersRef.current.forEach((_, peerUuid) => calls.signal(uuid, 'share', { on: false }, peerUuid).catch(() => undefined))
  }

  const toggleShare = async () => {
    if (sharing) {
      stopShare()
      return
    }
    try {
      const display = await navigator.mediaDevices.getDisplayMedia({ video: true })
      const raw = display.getVideoTracks()[0]
      displayTrackRef.current = raw
      raw.onended = stopShare
      // One composited track keeps the sharer's face on the call without a
      // second transceiver or a renegotiation. Same pipeline as meetings.
      const camera = cameraOff ? null : (blurRef.current?.track ?? cameraTrackRef.current ?? null)
      const composite = createSharePipeline(raw, camera)
      sharePipeRef.current = composite
      const track = composite.track
      peersRef.current.forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
        sender?.replaceTrack(track).catch(() => undefined)
      })
      showSelf(new MediaStream([track]))
      setSharing(true)
      const uuid = callRef.current?.uuid
      if (uuid) peersRef.current.forEach((_, peerUuid) => calls.signal(uuid, 'share', { on: true }, peerUuid).catch(() => undefined))
    } catch {
      /* user cancelled the picker */
    }
  }

  const toggleFullscreen = () => {
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => undefined)
    } else {
      // The panel, not just the video: fullscreening the video element's
      // wrapper left every control outside the fullscreen element, so mute,
      // camera and hang-up all vanished until you pressed Escape.
      panelRef.current?.requestFullscreen().catch(() => undefined)
    }
  }

  const toggleExpanded = () => {
    setExpanded((e) => {
      localStorage.setItem('mypa-call-expanded', e ? '0' : '1')
      return !e
    })
  }

  const broadcastMedia = (mic: boolean, cam: boolean) => {
    myMediaRef.current = { mic, cam }
    const uuid = callRef.current?.uuid
    if (!uuid) return
    peersRef.current.forEach((_, peerUuid) => {
      calls.signal(uuid, 'media', { mic, cam }, peerUuid).catch(() => undefined)
    })
  }

  const toggleMute = () => {
    const next = !muted
    localStreamRef.current?.getAudioTracks().forEach((t) => (t.enabled = !next))
    setMuted(next)
    broadcastMedia(!next, !cameraOff)
  }

  const toggleCamera = () => {
    const next = !cameraOff
    localStreamRef.current?.getVideoTracks().forEach((t) => (t.enabled = !next))
    if (blurRef.current) blurRef.current.track.enabled = !next
    setCameraOff(next)
    broadcastMedia(!muted, !next)
  }

  useEffect(() => {
    if (!tileMenu) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setTileMenu(null)
    const dismiss = () => setTileMenu(null)
    window.addEventListener('keydown', onKey)
    window.addEventListener('resize', dismiss)
    return () => {
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('resize', dismiss)
    }
  }, [tileMenu])

  const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

  const isVideo = activeCall?.type === 'video'
  const tiles = remotePeers.length
  /* A share, or a pin, takes the stage and the rest drop to a filmstrip —
     the same rule meetings use. Presenting is unreadable in a grid cell. */
  const sharer = remotePeers.find((p) => p.sharing)?.uuid ?? (sharing ? 'me' : null)
  const validPin = pinned === 'me' || remotePeers.some((p) => p.uuid === pinned) ? pinned : null
  const stageUuid = validPin ?? sharer
  const staged = isVideo && stageUuid !== null && stageUuid !== 'me' && tiles > 0
  const galleryPeers = staged ? remotePeers.filter((p) => p.uuid !== stageUuid) : remotePeers
  const gallery = useGalleryLayout(staged ? 0 : tiles)
  const tileStyle = gallery.width ? { width: gallery.width, height: gallery.height } : undefined
  const wide = activeCall?.isGroup && tiles > 1

  /*
   * A video call on a phone is not the desktop panel drawn smaller.
   *
   * It is the shape every phone calling app uses: the other person filling the
   * screen, you in a corner, and the buttons floating on top of both. The
   * windowed panel that works on a laptop turned into a 320px box covering the
   * page it was floating over, with its own controls scrolling sideways.
   */
  const { cameras } = useDevices(!!activeCall && isVideo)
  const phone = useIsPhone()
  const landscape = useLandscapePhone()
  /*
   * Offer the switch when there is somewhere to switch to.
   *
   * A phone always has a front and a back camera, but it does not always admit
   * to both: enumerateDevices returns labelled entries only once a permission
   * has stuck, and can report a single device until then. Waiting for the list
   * to agree would mean the button is missing exactly when it is wanted, so a
   * phone gets it unconditionally and a desktop gets it when it really does
   * have more than one webcam.
   */
  const canFlip = isVideo && (phone || cameras.length > 1 || !!deviceChoice.facing)
  // "Minimise" still drops it back to the floating corner panel, so the rest
  // of the app is reachable mid-call.
  const fullBleed = !!activeCall && isVideo && phone && expanded
  /*
   * Who is on the stage: whoever is pinned, else whoever is presenting, else
   * simply the first other person — a phone always has somebody big, unlike
   * the windowed panel where nothing on the stage means a plain grid.
   */
  const stageId = stageUuid ?? remotePeers[0]?.uuid ?? 'me'
  /** Double-tapping your own picture puts you on the stage, and back again. */
  const meOnStage = stageId === 'me'
  const swapSelf = () => setPinned(meOnStage ? null : 'me')
  const stagePeer = meOnStage ? null : remotePeers.find((p) => p.uuid === stageId) ?? null
  /** Everyone who is not on the stage, as corner tiles. */
  const cornerPeers = remotePeers.filter((p) => p.uuid !== stageId)
  const [moreOpen, setMoreOpen] = useState(false)

  /* One element, moved between the stage and the corner. Two would fight over
     the same stream; useSelfView re-attaches it whenever React re-mounts it. */
  const selfVideo = (
    <video
      ref={attachSelf}
      autoPlay
      playsInline
      muted
      onDoubleClick={swapSelf}
      className="h-full w-full object-cover -scale-x-100"
    />
  )

  return (
    <CallContext.Provider value={{ startCall, joinCall, endCall: hangUp, activeCall }}>
      {children}

      {/* Incoming call banner */}
      {incoming && (
        <div className="fixed inset-x-0 top-4 z-[70] mx-auto w-fit rounded-xl border border-slate-200 bg-white p-4 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
          <p className="text-sm font-semibold">
            {incoming.from_name ?? 'Someone'} is calling…
          </p>
          <p className="text-xs capitalize text-slate-400">{incoming.call_type} call</p>
          <div className="mt-3 flex gap-2">
            <Button size="sm" onClick={acceptIncoming}>
              <Phone className="size-3.5" /> Accept
            </Button>
            <Button size="sm" variant="danger" onClick={declineIncoming}>
              <PhoneOff className="size-3.5" /> Decline
            </Button>
          </div>
        </div>
      )}

      {/* A video call on a phone: full screen, controls floating over it. */}
      {activeCall && fullBleed && (
        <div ref={panelRef} className="fixed inset-0 z-[60] flex flex-col bg-black">
          <div ref={callBodyRef} className="relative min-h-0 flex-1">
            {/* The stage — whoever is not in a corner fills the screen. */}
            {meOnStage ? (
              <div className="absolute inset-0">{selfVideo}</div>
            ) : stagePeer ? (
              // The positioning goes on a wrapper: a tile is `relative` by
              // nature, and `absolute` passed in beside it is a coin toss over
              // which utility Tailwind emitted last.
              <div className="absolute inset-0">
                <RemoteTile
                  key={stagePeer.uuid}
                  peer={stagePeer}
                  video
                  cover
                  hideName
                  className="h-full w-full rounded-none"
                  onDoubleClick={() => setPinned(pinned === stagePeer.uuid ? null : stagePeer.uuid)}
                  onContextMenu={(e) => {
                    e.preventDefault()
                    setTileMenu({ uuid: stagePeer.uuid, name: stagePeer.name, x: e.clientX, y: e.clientY })
                  }}
                />
              </div>
            ) : (
              <div className="absolute inset-0 flex items-center justify-center text-sm text-white/60">
                {activeCall.status === 'ringing' ? 'Ringing…' : 'Waiting for them to join…'}
              </div>
            )}

            {/* Everyone else, down the side. Double-tap one to swap it in. */}
            {/* Capped and scrollable: a large group would otherwise run this
                column straight down over the buttons. Sideways it runs along
                the top instead — there is no vertical room to spare on a
                390px-tall screen. */}
            <div className={clsx(
              'scroll-pane pt-safe absolute right-3 top-3 z-10 flex gap-2 overflow-auto',
              landscape ? 'max-w-[55%] flex-row' : 'max-h-[calc(100%-10rem)] flex-col',
            )}>
              {!meOnStage && (
                <div
                  onDoubleClick={swapSelf}
                  className={clsx(
                    'shrink-0 overflow-hidden rounded-xl bg-slate-900 shadow-lg ring-1 ring-white/25',
                    landscape ? 'h-20 w-28' : 'h-32 w-24',
                  )}
                >
                  {selfVideo}
                </div>
              )}
              {cornerPeers.map((p) => (
                <RemoteTile
                  key={p.uuid}
                  peer={p}
                  video
                  cover
                  active={activeSpeaker === p.uuid}
                  className={clsx('shrink-0 rounded-xl shadow-lg ring-1 ring-white/25', landscape ? 'h-20 w-28' : 'h-32 w-24')}
                  onDoubleClick={() => setPinned(pinned === p.uuid ? null : p.uuid)}
                  onContextMenu={(e) => {
                    e.preventDefault()
                    setTileMenu({ uuid: p.uuid, name: p.name, x: e.clientX, y: e.clientY })
                  }}
                />
              ))}
            </div>

            {/* Who you are talking to. Kept clear of the corner tiles. */}
            <div className="pt-safe pointer-events-none absolute inset-x-0 top-0 z-0 bg-gradient-to-b from-black/70 to-transparent px-4 pb-10 pt-3">
              <p className="truncate pr-28 text-base font-semibold text-white">{activeCall.peerName}</p>
              <p className="text-xs text-white/70">
                {activeCall.status === 'ringing' && 'Ringing…'}
                {activeCall.status === 'connecting' && 'Connecting…'}
                {activeCall.status === 'ongoing' && fmt(elapsed)}
                {activeCall.isGroup && activeCall.status === 'ongoing' && ` · ${tiles + 1} in call`}
                {(pinned || sharer) && ' · pinned'}
              </p>
              {(recording || peerRecording) && (
                <p className="mt-1 inline-flex items-center gap-1.5 rounded-full bg-red-600/90 px-2 py-0.5 text-[11px] font-medium text-white">
                  <span className="size-1.5 rounded-full bg-white" />
                  Recording{recording && ' — you'}{peerRecording && ` — ${peerRecording}`}
                </p>
              )}
            </div>

            {/* Controls float over the picture rather than taking a strip of
                it, and the ones that do not fit live behind "More" rather
                than scrolling sideways off the edge of the screen. */}
            <div className="pb-safe absolute inset-x-0 bottom-0 z-20 bg-gradient-to-t from-black/85 via-black/50 to-transparent px-3 pb-4 pt-12">
              {recRequest && activeCall.direction === 'outgoing' && (
                <div className="mb-3 flex items-center justify-center gap-2 rounded-xl bg-white/95 px-3 py-2 text-xs text-slate-900">
                  <span className="font-medium">{recRequest.name}</span> wants to record
                  <Button size="sm" onClick={() => {
                    const uuid = callRef.current?.uuid
                    if (uuid) calls.signal(uuid, 'rec-allow', { ok: 1 }, recRequest.uuid).catch(() => undefined)
                    setRecRequest(null)
                  }}>
                    Allow
                  </Button>
                  <Button size="sm" variant="secondary" onClick={() => {
                    const uuid = callRef.current?.uuid
                    if (uuid) calls.signal(uuid, 'rec-deny', { ok: 0 }, recRequest.uuid).catch(() => undefined)
                    setRecRequest(null)
                  }}>
                    Deny
                  </Button>
                </div>
              )}

              {moreOpen && (
                <div className="mb-3 rounded-2xl bg-slate-900/95 p-3 ring-1 ring-white/15">
                  <div className="mb-2 flex items-center justify-between">
                    <p className="text-[11px] font-semibold uppercase tracking-wider text-white/50">More</p>
                    <button
                      aria-label="Close"
                      // Sized to the 44px hit area the stylesheet gives bare
                      // icon buttons, so it does not spill out of this row.
                      className="-mr-2 flex size-11 items-center justify-center text-white/60"
                      onClick={() => setMoreOpen(false)}
                    >
                      <X className="size-4" />
                    </button>
                  </div>
                  <div className="flex flex-wrap items-center justify-center gap-2">
                    <CircleButton on={recording} danger={recording} label={recording ? 'Stop recording' : 'Record this call'} onClick={toggleRecord}>
                      {recording ? <Square className="size-5" /> : <Circle className="size-5 text-red-500" />}
                    </CircleButton>
                    <BackgroundPicker active={bgLabel} busy={blurBusy} onPick={applyBackground} round />
                    <CircleButton label="Add someone to this call" onClick={() => { setMoreOpen(false); setShowInvite(true) }}>
                      <UserPlus className="size-5" />
                    </CircleButton>
                    <CircleButton label={isFs ? 'Exit fullscreen' : 'Fullscreen'} onClick={toggleFullscreen}>
                      <Expand className="size-5" />
                    </CircleButton>
                    <CircleButton label="Minimise to the corner" onClick={() => { setMoreOpen(false); toggleExpanded() }}>
                      <Minimize2 className="size-5" />
                    </CircleButton>
                  </div>
                  <p className="mt-2 text-center text-[11px] text-white/40">
                    Double-tap a picture to make it the big one.
                  </p>
                </div>
              )}

              <div className="flex items-center justify-center gap-3">
                <CircleButton on={muted} danger={muted} label={muted ? 'Unmute' : 'Mute'} onClick={toggleMute}>
                  {muted ? <MicOff className="size-5" /> : <Mic className="size-5" />}
                </CircleButton>
                <CircleButton on={cameraOff} danger={cameraOff} label="Camera on or off" onClick={toggleCamera}>
                  {cameraOff ? <VideoOff className="size-5" /> : <Video className="size-5" />}
                </CircleButton>
                {canFlip && (
                  <CircleButton
                    label={sharing ? 'Stop sharing to switch camera' : 'Switch camera (front/back)'}
                    onClick={flipCamera}
                  >
                    <SwitchCamera className={clsx('size-5', flipping && 'animate-spin')} />
                  </CircleButton>
                )}
                <CircleButton on={sharing} label={sharing ? 'Stop sharing my screen' : 'Share my screen'} onClick={toggleShare}>
                  <MonitorUp className="size-5" />
                </CircleButton>
                <CircleButton on={moreOpen} label="More options" onClick={() => setMoreOpen((o) => !o)}>
                  <MoreHorizontal className="size-5" />
                </CircleButton>
                <CircleButton danger label={activeCall.isGroup ? 'Leave the call' : 'End the call'} onClick={hangUp}>
                  <PhoneOff className="size-5" />
                </CircleButton>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Active call window */}
      {activeCall && !fullBleed && (
        <div
          ref={panelRef}
          className={clsx(
            'z-[60] flex flex-col overflow-hidden border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900',
            isFs
              // Fullscreen now covers the panel, so the controls come with it.
              ? 'fixed inset-0 rounded-none'
              : expanded
                // Big centred window — the old panel was a fixed 20rem box with
                // no way to make the other person any larger.
                ? 'fixed inset-4 rounded-xl sm:inset-8 lg:inset-x-[12%] lg:inset-y-10'
                : clsx(
                    // Above the bottom tab bar on a phone, which the panel
                    // otherwise covers along with its call and chat buttons.
                    'fixed bottom-20 right-4 rounded-xl sm:bottom-4',
                    wide ? 'w-[34rem] max-w-[calc(100vw-2rem)]' : 'w-80 max-w-[calc(100vw-2rem)]',
                  ),
          )}
        >
          <div
            ref={callBodyRef}
            className={clsx('relative min-h-0 bg-slate-950 p-1', (isFs || expanded) && 'flex-1')}
          >
            {isVideo ? (
              <>
                <div className={clsx('flex flex-col gap-1', isFs || expanded ? 'h-full' : 'h-56')}>
                  {remotePeers.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center text-xs text-slate-500">Waiting for others…</div>
                  ) : staged ? (
                    <>
                      {/* Filmstrip above the stage, as every meeting app does. */}
                      {galleryPeers.length > 0 && (
                        <div className="flex h-16 shrink-0 justify-center gap-1 overflow-x-auto sm:h-20">
                          {galleryPeers.map((p) => (
                            <RemoteTile
                              key={p.uuid} peer={p} video active={activeSpeaker === p.uuid}
                              className="aspect-video h-full shrink-0"
                              onDoubleClick={() => setPinned(pinned === p.uuid ? null : p.uuid)}
                              onContextMenu={(e) => { e.preventDefault(); setTileMenu({ uuid: p.uuid, name: p.name, x: e.clientX, y: e.clientY }) }}
                            />
                          ))}
                        </div>
                      )}
                      <div className="min-h-0 flex-1">
                        {remotePeers.filter((p) => p.uuid === stageUuid).map((p) => (
                          <RemoteTile
                            key={p.uuid} peer={p} video active={activeSpeaker === p.uuid} className="h-full min-h-0"
                            onContextMenu={(e) => { e.preventDefault(); setTileMenu({ uuid: p.uuid, name: p.name, x: e.clientX, y: e.clientY }) }}
                          />
                        ))}
                      </div>
                    </>
                  ) : (
                    // Centred wrapping row, sized from the measurement above, so
                    // a short last row sits in the middle rather than the left.
                    <div ref={gallery.attach} className="flex min-h-0 flex-1 flex-wrap content-center items-center justify-center gap-1">
                      {galleryPeers.map((p) => (
                        <RemoteTile
                          key={p.uuid} peer={p} video active={activeSpeaker === p.uuid}
                          className="shrink-0" style={tileStyle}
                          onContextMenu={(e) => { e.preventDefault(); setTileMenu({ uuid: p.uuid, name: p.name, x: e.clientX, y: e.clientY }) }}
                        />
                      ))}
                    </div>
                  )}
                </div>
                <video
                  ref={attachSelf}
                  autoPlay
                  playsInline
                  muted
                  className={clsx(
                    'absolute bottom-2 right-2 rounded-lg border border-slate-700 object-cover -scale-x-100',
                    isFs || expanded ? 'h-32 w-48' : 'h-16 w-24',
                  )}
                />
              </>
            ) : (
              <div className="flex h-24 items-center justify-center gap-2">
                {remotePeers.map((p) => (
                  <RemoteTile key={p.uuid} peer={p} video={false} />
                ))}
                <video ref={attachSelf} className="hidden" muted />
                {activeCall.isGroup ? (
                  <Users className="size-8 text-slate-500" />
                ) : (
                  <Phone className="size-8 text-slate-500" />
                )}
                {activeCall.isGroup && (
                  <span className="text-xs text-slate-400">{tiles + 1} in call</span>
                )}
              </div>
            )}
          </div>
          {/* overflow-visible, not auto: the background picker opens upward out
              of this row, and an `overflow` of any kind on the row cut it off —
              which is what "the settings hide behind the video" was. */}
          <div className="shrink-0 overflow-visible p-3">
            {recRequest && activeCall.direction === 'outgoing' && (
              <div className="mb-1.5 flex items-center gap-1.5 rounded bg-red-50 px-2 py-1 text-[11px] dark:bg-red-950">
                <span className="font-medium">{recRequest.name}</span> wants to record
                <Button size="sm" onClick={() => {
                  const uuid = callRef.current?.uuid
                  if (uuid) calls.signal(uuid, 'rec-allow', { ok: 1 }, recRequest.uuid).catch(() => undefined)
                  setRecRequest(null)
                }}>
                  Allow
                </Button>
                <Button size="sm" variant="secondary" onClick={() => {
                  const uuid = callRef.current?.uuid
                  if (uuid) calls.signal(uuid, 'rec-deny', { ok: 0 }, recRequest.uuid).catch(() => undefined)
                  setRecRequest(null)
                }}>
                  Deny
                </Button>
              </div>
            )}
            {(recording || peerRecording) && (
              <div className="mb-1.5 flex items-center gap-1.5 rounded bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                <span className="relative flex size-1.5">
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75" />
                  <span className="relative inline-flex size-1.5 rounded-full bg-red-600" />
                </span>
                Recording in progress{recording && ' — you'}{peerRecording && ` — ${peerRecording}`}
              </div>
            )}
            <p className="text-sm font-semibold">{activeCall.peerName}</p>
            <p className="text-xs text-slate-400">
              {activeCall.status === 'ringing' && 'Ringing…'}
              {activeCall.status === 'connecting' && 'Connecting…'}
              {activeCall.status === 'ongoing' && fmt(elapsed)}
              {activeCall.isGroup && activeCall.status === 'ongoing' && ` · ${tiles + 1} participants`}
            </p>
            <div className="mt-2 flex gap-2">
              <Button size="sm" variant="secondary" onClick={toggleMute} title={muted ? 'Unmute' : 'Mute'}>
                {muted ? <MicOff className="size-3.5 text-red-500" /> : <Mic className="size-3.5" />}
              </Button>
              {isVideo && (
                <Button size="sm" variant="secondary" onClick={toggleCamera} title="Toggle camera">
                  {cameraOff ? <VideoOff className="size-3.5 text-red-500" /> : <Video className="size-3.5" />}
                </Button>
              )}
              {canFlip && (
                <Button
                  size="sm"
                  variant="secondary"
                  title={sharing ? 'Stop sharing to switch camera' : 'Switch camera (front/back)'}
                  onClick={flipCamera}
                  disabled={flipping || sharing}
                >
                  <SwitchCamera className={clsx('size-3.5', flipping && 'animate-spin')} />
                </Button>
              )}
              {isVideo && (
                <Button size="sm" variant={sharing ? 'primary' : 'secondary'} title={sharing ? 'Stop sharing my screen' : 'Share my screen'} onClick={toggleShare}>
                  <MonitorUp className="size-3.5" />
                </Button>
              )}
              {isVideo && (
                <Button
                  size="sm"
                  variant={expanded ? 'primary' : 'secondary'}
                  title={expanded ? 'Shrink to the corner' : 'Make the call bigger'}
                  onClick={toggleExpanded}
                >
                  {expanded ? <Minimize2 className="size-3.5" /> : <Maximize2 className="size-3.5" />}
                </Button>
              )}
              {isVideo && (
                <Button size="sm" variant="secondary" title={isFs ? 'Exit fullscreen' : 'Fullscreen'} onClick={toggleFullscreen}>
                  <Expand className="size-3.5" />
                </Button>
              )}
              <Button
                size="sm"
                variant={recording ? 'danger' : 'secondary'}
                title={recording
                  ? 'Stop recording (saves to Downloads)'
                  : activeCall.direction === 'outgoing'
                    ? 'Record this call — the other side is notified'
                    : 'Ask the caller for permission to record'}
                onClick={toggleRecord}
                disabled={recPending}
              >
                {recording ? <Square className="size-3.5" /> : <Circle className="size-3.5 text-red-500" />}
              </Button>
              {isVideo && (
                <BackgroundPicker active={bgLabel} busy={blurBusy} onPick={applyBackground} compact />
              )}
              <Button
                size="sm"
                variant="secondary"
                title="Add someone to this call"
                onClick={() => setShowInvite(true)}
              >
                <UserPlus className="size-3.5" />
              </Button>
              <Button size="sm" variant="danger" onClick={hangUp} className="ml-auto">
                <PhoneOff className="size-3.5" /> {activeCall.isGroup ? 'Leave' : 'End'}
              </Button>
            </div>
          </div>
        </div>
      )}

      {showInvite && activeCall && (
        <PickUserModal
          title="Add someone to this call"
          actionLabel="Ring them"
          onClose={() => setShowInvite(false)}
          onSubmit={(identifier) => {
            const uuid = callRef.current?.uuid
            if (!uuid) return
            calls.invite(uuid, identifier)
              .then((res) => toast(res.message, 'success'))
              .catch((err) => toastError(errorMessage(err)))
          }}
        />
      )}

      {tileMenu && (
        <>
          <div className="fixed inset-0 z-[75]" onMouseDown={() => setTileMenu(null)} onContextMenu={(e) => { e.preventDefault(); setTileMenu(null) }} />
          <div
            className="fixed z-[76] min-w-44 rounded-xl border border-slate-200 bg-white p-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900"
            style={{
              // Kept inside the window; a right-click near an edge would
              // otherwise open the menu off-screen.
              left: Math.min(tileMenu.x, window.innerWidth - 190),
              top: Math.min(tileMenu.y, window.innerHeight - 90),
            }}
          >
            <p className="truncate px-2 py-1 text-[11px] uppercase tracking-wide text-slate-400">{tileMenu.name}</p>
            <button
              className="tap flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
              onClick={() => { setPinned(pinned === tileMenu.uuid ? null : tileMenu.uuid); setTileMenu(null) }}
            >
              {pinned === tileMenu.uuid ? <><PinOff className="size-4" /> Unpin</> : <><Pin className="size-4" /> Pin for me</>}
            </button>
            <p className="px-2 pb-1 pt-0.5 text-[11px] text-slate-400">Only changes your view.</p>
          </div>
        </>
      )}
    </CallContext.Provider>
  )
}
