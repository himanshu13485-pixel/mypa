import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Circle, Copy, Expand, FlipHorizontal, Grid3x3, Hand, LayoutGrid, Lock, LockOpen, MessageSquare, Mic, MicOff,
  MonitorUp, MoreHorizontal, Paperclip, PhoneOff, PictureInPicture2, Pin, PinOff, Rows3, Settings2, SmilePlus,
  Square, SwitchCamera, User, Users, Video, VideoOff,
} from 'lucide-react'
import { clsx } from 'clsx'
import { calls, meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { getEcho } from '../lib/echo'
import { startCompositeRecording, type CompositeRecorder } from '../lib/recorder'
import { createEffectTrack, createSharePipeline, type BlurPipeline } from '../lib/videoFx'
import { VIDEO_FIT, galleryColumns, galleryRows as galleryRows_, useSelfView } from '../lib/videoLayout'
import { useActiveSpeaker } from '../lib/activeSpeaker'
import { usePeerQuality } from '../lib/netQuality'
import {
  AUDIO_CONSTRAINTS, VIDEO_CONSTRAINTS, applySpeaker, loadDeviceChoice, nextCamera, openCamera, openMic,
  saveDeviceChoice, speakerSelectionSupported, swapTrack, useDevices, type DeviceChoice,
} from '../lib/devices'
import { keepScreenAwake, openPip, pipSupport, type PipSession } from '../lib/pip'
import { useIsPhone } from '../lib/useMediaQuery'
import BackgroundPicker, { type BackgroundChoice } from '../components/BackgroundPicker'
import MeetingLobby, { type LobbyResult } from '../components/MeetingLobby'
import ParticipantsPanel, { QualityDot } from '../components/ParticipantsPanel'
import { useAuthStore } from '../stores/auth'
import { useToast } from '../components/Toast'
import { Button, Card } from '../components/ui'
import { meetingLink } from './MeetingsPage'
import type { MeetingHostAction, MeetingParticipant, MeetingSignalPayload } from '../types'
import { normalizeSdp } from '../lib/sdp'

const REACTIONS: Record<string, string> = {
  thumbsup: '\u{1F44D}', clap: '\u{1F44F}', heart: '\u{2764}\u{FE0F}', laugh: '\u{1F602}',
  wow: '\u{1F62E}', party: '\u{1F389}', yes: '\u{2705}', no: '\u{274C}',
  slower: '\u{1F422}', faster: '\u{1F407}', hand: '\u{270B}',
}

type Layout = 'gallery' | 'speaker' | 'sidebar'

interface Peer {
  uuid: string
  name: string
  stream: MediaStream | null
  sharing?: boolean
  micOff?: boolean
  camOff?: boolean
  /** ICE state, surfaced on the tile until the media path is established. */
  conn?: string
}

function PeerTile({
  peer, video, burst, hand, role, active, spotlight, pinned, quality, className, onContextMenu,
}: {
  peer: Peer
  video: boolean
  burst?: string
  hand?: boolean
  role?: string
  active?: boolean
  spotlight?: boolean
  pinned?: boolean
  quality?: import('../lib/netQuality').PeerStats
  className?: string
  onContextMenu?: (e: React.MouseEvent) => void
}) {
  const attach = (el: HTMLVideoElement | HTMLAudioElement | null) => {
    if (el && el.srcObject !== peer.stream) {
      el.srcObject = peer.stream
      el.play().catch(() => undefined)
    }
  }
  const isHost = role === 'host'

  if (!video) {
    return (
      <div className={clsx('flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-sm text-white', active && 'ring-2 ring-emerald-400')}>
        <audio ref={attach} autoPlay />
        <span className="flex size-7 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold">
          {peer.name.charAt(0)}
        </span>
        {peer.name}{isHost && <span className="text-[10px] text-amber-400"> (Host)</span>}
        {peer.micOff && <MicOff className="size-3.5 text-red-500" />}
        {hand && <span className="text-base">{REACTIONS.hand}</span>}
        {burst && <span className="animate-bounce text-xl">{REACTIONS[burst]}</span>}
      </div>
    )
  }
  return (
    <div
      onContextMenu={onContextMenu}
      className={clsx(
        'relative min-h-0 overflow-hidden rounded-lg bg-slate-900 transition-shadow',
        active && 'ring-2 ring-emerald-400',
        spotlight && 'ring-2 ring-amber-400',
        className,
      )}
    >
      <video ref={attach} autoPlay playsInline className={VIDEO_FIT} />
      {peer.camOff && (
        <div className="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-slate-900 text-slate-300">
          <span className="flex size-12 items-center justify-center rounded-full bg-brand-700 text-lg font-semibold text-white">
            {peer.name.charAt(0)}
          </span>
          <VideoOff className="size-4 text-red-500" />
        </div>
      )}
      {peer.conn && !['connected', 'completed'].includes(peer.conn) && (
        <span
          className={clsx(
            'absolute left-1.5 top-1.5 rounded px-1.5 py-0.5 text-[10px] font-semibold',
            peer.conn === 'failed' ? 'bg-red-600 text-white' : 'bg-amber-400 text-black',
          )}
        >
          {peer.conn === 'failed' ? 'reconnecting…' : `connecting… ${peer.conn}`}
        </span>
      )}
      <span className="absolute bottom-1.5 left-1.5 flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white">
        {peer.name}
        {isHost && ' (Host)'}
        {role === 'cohost' && ' (Co-host)'}
        {pinned && <span title="Pinned for you">📌</span>}
        {hand && REACTIONS.hand}
        {peer.micOff && <MicOff className="inline size-3 text-red-500" />}
        {peer.camOff && <VideoOff className="inline size-3 text-red-500" />}
        <QualityDot stats={quality} />
      </span>
      {burst && (
        <span className="absolute right-2 top-2 animate-bounce text-4xl drop-shadow">{REACTIONS[burst]}</span>
      )}
    </div>
  )
}

/**
 * The meeting room: a WebRTC mesh among everyone who opened this meeting's
 * link. Same rule as calls — whoever JOINS sends the offers to everyone
 * already inside — plus screen sharing via video-track replacement.
 *
 * Presence is heartbeat-driven: the server sweeps anyone who stops pinging,
 * so a closed tab or a dead connection empties the room (and ends the
 * meeting) exactly like a clean "leave" would.
 */
export default function MeetingRoomPage() {
  const { code = '' } = useParams()
  const navigate = useNavigate()
  const user = useAuthStore((s) => s.user)
  const { toast, toastError } = useToast()

  const [peers, setPeers] = useState<Peer[]>([])
  const [phase, setPhase] = useState<'loading' | 'lobby' | 'joining' | 'waiting' | 'denied' | 'removed' | 'in' | 'ended' | 'error'>('loading')
  const [errorMsg, setErrorMsg] = useState('')
  const [lobbyError, setLobbyError] = useState<string | null>(null)
  const [muted, setMuted] = useState(false)
  const [cameraOff, setCameraOff] = useState(false)
  const [sharing, setSharing] = useState(false)
  const [elapsed, setElapsed] = useState(0)
  const [copied, setCopied] = useState(false)
  const [myName, setMyName] = useState<string | null>(null)
  const [showReactions, setShowReactions] = useState(false)
  const [bursts, setBursts] = useState<Record<string, string>>({}) // uuid -> emoji key
  const [hands, setHands] = useState<Set<string>>(new Set())
  const [myHand, setMyHand] = useState(false)
  const [recording, setRecording] = useState(false)
  const [recorders, setRecorders] = useState<string[]>([]) // who else records
  const [recPending, setRecPending] = useState(false)
  const [recRequests, setRecRequests] = useState<{ uuid: string; name: string }[]>([])
  const [isFs, setIsFs] = useState(false)
  const [bgLabel, setBgLabel] = useState('none')
  const [blurBusy, setBlurBusy] = useState(false)
  /**
   * Your own tile is flipped so it behaves like a mirror, which is what people
   * expect of their own face — but the flip applies to the whole frame, so any
   * text in a virtual background comes out backwards. Only you saw it; peers
   * always received the unflipped track. An image background therefore
   * suppresses the mirror, and it is a preference besides.
   */
  const [mirror, setMirror] = useState(() => loadDeviceChoice().mirror !== false)
  const [bgHasImage, setBgHasImage] = useState(false)
  const mirrorSelf = mirror && !bgHasImage

  // Room state mirrored from the server (heartbeat + signals).
  const [roster, setRoster] = useState<MeetingParticipant[]>([])
  const [myRole, setMyRole] = useState<'host' | 'cohost' | 'participant'>('participant')
  const [isLocked, setIsLocked] = useState(false)
  const [spotlight, setSpotlight] = useState<string | null>(null)
  const [unmuteAsk, setUnmuteAsk] = useState<string | null>(null)

  // Presentation.
  const [layout, setLayout] = useState<Layout>('gallery')
  const [pinned, setPinned] = useState<string | null>(null)
  /**
   * Right-click menu on a tile. Pinning was only reachable from the
   * participants panel, which is a long way to go to enlarge someone.
   * It stays local to this browser — nobody else's view changes.
   */
  const [tileMenu, setTileMenu] = useState<{ uuid: string; name: string; x: number; y: number } | null>(null)

  useEffect(() => {
    if (!tileMenu) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setTileMenu(null)
    // Scrolling or resizing leaves the menu stranded away from its tile.
    const dismiss = () => setTileMenu(null)
    window.addEventListener('keydown', onKey)
    window.addEventListener('resize', dismiss)
    return () => {
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('resize', dismiss)
    }
  }, [tileMenu])
  const [hideSelf, setHideSelf] = useState(false)
  const [showPeople, setShowPeople] = useState(false)
  const [showSettings, setShowSettings] = useState(false)
  const [moreOpen, setMoreOpen] = useState(false)
  const [deviceChoice, setDeviceChoice] = useState<DeviceChoice>(loadDeviceChoice)
  const [flipping, setFlipping] = useState(false)
  const [pip, setPip] = useState<PipSession | null>(null)

  const myMediaRef = useRef({ mic: true, cam: true })
  const recorderRef = useRef<CompositeRecorder | null>(null)
  const startRecRef = useRef<() => void>(() => undefined)
  const blurRef = useRef<BlurPipeline | null>(null)
  const bgChoiceRef = useRef<BackgroundChoice | null>(null)
  const tilesRef = useRef<HTMLDivElement>(null)
  const [knocks, setKnocks] = useState<{ uuid: string; name: string }[]>([])
  const [approvalOn, setApprovalOn] = useState<boolean | null>(null)
  const [chatOpen, setChatOpen] = useState(false)
  const [chatUnread, setChatUnread] = useState(0)
  const [chatTo, setChatTo] = useState('') // '' = everyone
  const [chatDraft, setChatDraft] = useState('')
  const [chatMsgs, setChatMsgs] = useState<{ from: string; name: string; text: string; priv: boolean; me: boolean; file?: { uuid: string; name: string; mime: string | null; size: number } }[]>([])
  const chatFileRef = useRef<HTMLInputElement>(null)
  const chatOpenRef = useRef(false)
  chatOpenRef.current = chatOpen

  const pcsRef = useRef<Map<string, RTCPeerConnection>>(new Map())
  const pendingIceRef = useRef<Map<string, RTCIceCandidateInit[]>>(new Map())
  const localStreamRef = useRef<MediaStream | null>(null)
  const cameraTrackRef = useRef<MediaStreamTrack | null>(null)
  const displayTrackRef = useRef<MediaStreamTrack | null>(null)
  /** Canvas compositor drawing the camera onto the shared screen. */
  const sharePipeRef = useRef<BlurPipeline | null>(null)
  /* Shared with calls and screen sessions. Keeps the self-view attached
     across the re-mounts that switching layout causes — see useSelfView. */
  const { show: showSelf, attach: attachSelf } = useSelfView()
  const iceServersRef = useRef<RTCIceServer[] | null>(null)
  const joinedRef = useRef(false)
  const lobbyRef = useRef<LobbyResult | null>(null)
  const restartTimersRef = useRef<Map<string, number>>(new Map())
  const wakeLockRef = useRef<{ release: () => void } | null>(null)

  const { data: meeting } = useQuery({
    queryKey: ['meeting', code],
    queryFn: () => meetingsApi.show(code),
    retry: false,
  })
  const isVideo = meeting?.type !== 'audio'
  const canModerate = myRole === 'host' || myRole === 'cohost'
  const isHost = myRole === 'host'

  const isPhone = useIsPhone()
  const { cameras } = useDevices(phase === 'in')
  const quality = usePeerQuality(useCallback(() => pcsRef.current, []), phase === 'in')
  const activeSpeaker = useActiveSpeaker([
    { uuid: 'me', stream: localStreamRef.current },
    ...peers.map((p) => ({ uuid: p.uuid, stream: p.stream })),
  ])

  /** Roster lookups by uuid — the server is authoritative for these. */
  const rosterMap = useMemo(() => {
    const map = new Map<string, MeetingParticipant>()
    for (const p of roster) map.set(p.uuid, p)
    return map
  }, [roster])

  const me: MeetingParticipant = useMemo(() => ({
    uuid: user?.uuid ?? 'me',
    name: myName ?? user?.name ?? 'You',
    role: myRole,
    mic_on: !muted,
    cam_on: !cameraOff,
    hand_raised: myHand,
  }), [user?.uuid, user?.name, myName, myRole, muted, cameraOff, myHand])

  const ensureLocalStream = useCallback(async () => {
    if (localStreamRef.current) return localStreamRef.current
    const choice = loadDeviceChoice()
    const audio: MediaTrackConstraints = {
      ...AUDIO_CONSTRAINTS,
      ...(choice.micId ? { deviceId: { exact: choice.micId } } : {}),
    }
    const video: MediaTrackConstraints = {
      ...VIDEO_CONSTRAINTS,
      ...(choice.cameraId ? { deviceId: { exact: choice.cameraId } } : {}),
    }

    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio,
        video: meeting?.type !== 'audio' ? video : false,
      })
    } catch (err) {
      // Camera busy (another app/browser window) or missing: join with mic
      // only instead of failing the whole meeting.
      if (meeting?.type !== 'audio') {
        console.warn('[meeting] camera unavailable, falling back to audio-only', err)
        stream = await navigator.mediaDevices.getUserMedia({ audio: true })
        setCameraOff(true)
      } else {
        throw err
      }
    }

    // Carry the lobby's mic/camera choice into the room.
    const wanted = lobbyRef.current
    if (wanted) {
      stream.getAudioTracks().forEach((t) => (t.enabled = wanted.micOn))
      stream.getVideoTracks().forEach((t) => (t.enabled = wanted.camOn))
    }

    localStreamRef.current = stream
    cameraTrackRef.current = stream.getVideoTracks()[0] ?? null
    showSelf(stream)
    return stream
  }, [meeting?.type, showSelf])

  const flushPendingIce = useCallback((peerUuid: string) => {
    const pc = pcsRef.current.get(peerUuid)
    const pending = pendingIceRef.current.get(peerUuid)
    if (!pc || !pc.remoteDescription || !pending?.length) return
    pendingIceRef.current.delete(peerUuid)
    for (const c of pending) pc.addIceCandidate(c).catch(() => undefined)
  }, [])

  /**
   * A dropped route can usually be repaired without rebuilding the call.
   * Only one side may restart or the two offers collide, so the peer with the
   * lower uuid does it — an arbitrary but consistent tie-break.
   */
  const restartIce = useCallback(async (peerUuid: string) => {
    const pc = pcsRef.current.get(peerUuid)
    if (!pc || !user?.uuid || user.uuid > peerUuid) return
    try {
      const offer = await pc.createOffer({ iceRestart: true })
      await pc.setLocalDescription(offer)
      await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, peerUuid)
      console.info('[meeting] ice restart sent to', peerUuid)
    } catch (err) {
      console.warn('[meeting] ice restart failed', err)
    }
  }, [code, user?.uuid])

  const createPeer = useCallback(
    async (peerUuid: string, peerName: string) => {
      const existing = pcsRef.current.get(peerUuid)
      if (existing) return existing

      if (!iceServersRef.current) iceServersRef.current = (await calls.config()).iceServers
      const pc = new RTCPeerConnection({ iceServers: iceServersRef.current })
      pcsRef.current.set(peerUuid, pc)

      const stream = await ensureLocalStream()
      stream.getTracks().forEach((t) => pc.addTrack(t, stream))

      // Allow decent video bandwidth so 720p does not get crushed.
      pc.getSenders().forEach((sender) => {
        if (sender.track?.kind !== 'video') return
        const params = sender.getParameters()
        params.encodings = params.encodings?.length ? params.encodings : [{}]
        params.encodings[0].maxBitrate = 1_500_000
        sender.setParameters(params).catch(() => undefined)
      })

      setPeers((p) => (p.some((x) => x.uuid === peerUuid) ? p : [...p, { uuid: peerUuid, name: peerName, stream: null }]))

      pc.ontrack = (event) => {
        const [remote] = event.streams
        console.info('[meeting] track from', peerUuid, remote.getTracks().map((t) => t.kind).join('+'))
        setPeers((p) => p.map((x) => (x.uuid === peerUuid ? { ...x, stream: remote } : x)))
      }
      pc.oniceconnectionstatechange = () => {
        const state = pc.iceConnectionState
        console.info('[meeting] ice state', peerUuid, state)
        setPeers((p) => p.map((x) => (x.uuid === peerUuid ? { ...x, conn: state } : x)))

        const timers = restartTimersRef.current
        if (state === 'failed') {
          void restartIce(peerUuid)
        } else if (state === 'disconnected') {
          // Give it a moment — a brief blip often heals on its own.
          if (!timers.has(peerUuid)) {
            timers.set(peerUuid, window.setTimeout(() => {
              timers.delete(peerUuid)
              if (pcsRef.current.get(peerUuid)?.iceConnectionState === 'disconnected') void restartIce(peerUuid)
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
          console.debug('[meeting] local candidate', event.candidate.type, event.candidate.protocol)
          meetingsApi.signal(code, 'ice', { candidate: event.candidate.toJSON() }, peerUuid).catch((err) => {
            console.warn('[meeting] could not send candidate', err)
          })
        }
      }
      pc.onicecandidateerror = (event) => {
        console.warn('[meeting] ice candidate error', (event as RTCPeerConnectionIceErrorEvent).errorText)
      }
      return pc
    },
    [code, ensureLocalStream, restartIce],
  )

  const removePeer = useCallback((peerUuid: string) => {
    pcsRef.current.get(peerUuid)?.close()
    pcsRef.current.delete(peerUuid)
    const t = restartTimersRef.current.get(peerUuid)
    if (t) {
      clearTimeout(t)
      restartTimersRef.current.delete(peerUuid)
    }
    setPeers((p) => p.filter((x) => x.uuid !== peerUuid))
  }, [])

  const showBurst = useCallback((uuid: string, key: string) => {
    if (key === 'hand') {
      setHands((h) => new Set(h).add(uuid))
      return
    }
    if (key === 'hand_down') {
      setHands((h) => {
        const next = new Set(h)
        next.delete(uuid)
        return next
      })
      return
    }
    setBursts((b) => ({ ...b, [uuid]: key }))
    setTimeout(() => setBursts((b) => {
      const next = { ...b }
      if (next[uuid] === key) delete next[uuid]
      return next
    }), 3500)
  }, [])

  const teardown = useCallback(() => {
    pcsRef.current.forEach((pc) => pc.close())
    pcsRef.current.clear()
    pendingIceRef.current.clear()
    restartTimersRef.current.forEach((t) => clearTimeout(t))
    restartTimersRef.current.clear()
    localStreamRef.current?.getTracks().forEach((t) => t.stop())
    localStreamRef.current = null
    sharePipeRef.current?.stop()
    sharePipeRef.current = null
    displayTrackRef.current?.stop() // otherwise the browser keeps sharing the screen
    displayTrackRef.current = null
    setSharing(false)
    recorderRef.current?.stop()
    recorderRef.current = null
    setRecording(false)
    blurRef.current?.stop()
    blurRef.current = null
    setBgLabel('none')
    setPeers([])
    setRoster([])
    wakeLockRef.current?.release()
    wakeLockRef.current = null
  }, [])

  const joinRoom = useCallback(async (lobby?: LobbyResult) => {
    if (lobby) lobbyRef.current = lobby
    const opts = lobbyRef.current
    setPhase('joining')
    try {
      await ensureLocalStream()
      const info = await meetingsApi.join(code, {
        ...(opts?.displayName ? { display_name: opts.displayName } : {}),
        ...(opts?.passcode ? { passcode: opts.passcode } : {}),
        mic_on: opts ? opts.micOn : true,
        cam_on: opts ? opts.camOn : true,
      })
      if ('waiting' in info && info.waiting) {
        setPhase('waiting')
        return
      }
      const room = info as Exclude<typeof info, { waiting: true }>
      joinedRef.current = true
      setPhase('in')
      setApprovalOn(room.requires_approval ?? null)
      setMyRole(room.my_role ?? (room.is_host ? 'host' : 'participant'))
      setIsLocked(!!room.is_locked)
      setSpotlight(room.spotlight_uuid ?? null)
      if (opts) {
        setMuted(!opts.micOn)
        setCameraOff(!opts.camOn)
        myMediaRef.current = { mic: opts.micOn, cam: opts.camOn }
        if (opts.displayName) setMyName(opts.displayName)
      }
      setRoster(room.joined_peers ?? [])
      keepScreenAwake().then((lock) => (wakeLockRef.current = lock))

      for (const peer of room.joined_peers ?? []) {
        const pc = await createPeer(peer.uuid, peer.name)
        const offer = await pc.createOffer()
        await pc.setLocalDescription(offer)
        await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, peer.uuid)
      }
    } catch (err) {
      const status = (err as { response?: { status?: number } }).response?.status
      const message = errorMessage(err)
      // 403 (passcode) and 423 (locked) are things the user can fix from the
      // lobby, so send them back there instead of to a dead end.
      if (status === 403 || status === 423) {
        setLobbyError(message)
        setPhase('lobby')
        return
      }
      setPhase('error')
      const raw = err instanceof Error ? err.message : ''
      setErrorMsg(
        /video source|could not start|notreadable|in use|notallowed|permission/i.test(raw)
          ? 'Your camera or microphone is busy or blocked - close the other app/browser window using it (or allow access) and try again.'
          : message || 'Could not join the meeting.',
      )
      console.warn('[meeting] join failed', err)
    }
  }, [code, createPeer, ensureLocalStream])

  // Show the lobby once the meeting is loaded. Screen-share codes belong to
  // the Screen module - send them there instead of a meeting room.
  useEffect(() => {
    if (!meeting || phase !== 'loading') return
    if (meeting.is_screen) {
      navigate(`/screen/session/${code}`, { replace: true })
      return
    }
    setPhase('lobby')
  }, [meeting, navigate, code, phase])

  // Signalling listener — shares the personal channel with calls, so only
  // stop OUR listener on unmount (never leave the channel itself).
  useEffect(() => {
    if (!user?.uuid) return
    const echo = getEcho()
    if (!echo) return
    const channel = echo.private(`user.${user.uuid}`)

    const handler = async (signal: MeetingSignalPayload) => {
      if (signal.meeting_code !== code) return
      switch (signal.signal) {
        case 'join':
          setPeers((p) => (p.some((x) => x.uuid === signal.from_uuid) ? p : p)) // roster only; offers arrive next
          break
        case 'leave':
          removePeer(signal.from_uuid)
          setRoster((r) => r.filter((x) => x.uuid !== signal.from_uuid))
          break
        case 'rename':
          setPeers((p) => p.map((x) => (x.uuid === signal.from_uuid ? { ...x, name: (signal.payload.name as string) ?? x.name } : x)))
          setRoster((r) => r.map((x) => (x.uuid === signal.from_uuid ? { ...x, name: (signal.payload.name as string) ?? x.name } : x)))
          break
        case 'react':
          showBurst(signal.from_uuid, signal.payload.emoji as string)
          break
        case 'knock':
          setKnocks((k) => (k.some((x) => x.uuid === signal.from_uuid) ? k : [...k, { uuid: signal.from_uuid, name: signal.from_name ?? 'Someone' }]))
          break
        case 'admitted':
          joinRoom()
          break
        case 'denied':
          teardown()
          setPhase('denied')
          break
        case 'share':
          setPeers((p) => p.map((x) => (x.uuid === signal.from_uuid ? { ...x, sharing: !!signal.payload.on } : x)))
          break
        case 'record': {
          const who = signal.from_name ?? 'Someone'
          setRecorders((r) => (signal.payload.on ? (r.includes(who) ? r : [...r, who]) : r.filter((n) => n !== who)))
          break
        }
        case 'media':
          setPeers((p) => p.map((x) => (x.uuid === signal.from_uuid
            ? { ...x, micOff: signal.payload.mic === false, camOff: signal.payload.cam === false }
            : x)))
          setRoster((r) => r.map((x) => (x.uuid === signal.from_uuid
            ? { ...x, mic_on: signal.payload.mic !== false, cam_on: signal.payload.cam !== false }
            : x)))
          break
        case 'rec-request':
          setRecRequests((r) => (r.some((x) => x.uuid === signal.from_uuid) ? r : [...r, { uuid: signal.from_uuid, name: signal.from_name ?? 'Someone' }]))
          break
        case 'rec-allow':
          setRecPending(false)
          startRecRef.current()
          break
        case 'rec-deny':
          setRecPending(false)
          toast('The host did not allow recording.', 'error')
          break
        case 'host-mute':
          forceMute(signal.from_name ?? 'The host')
          break
        case 'host-ask-unmute':
          setUnmuteAsk(signal.from_name ?? 'The host')
          break
        case 'host-stop-video':
          forceCameraOff(signal.from_name ?? 'The host')
          break
        case 'removed':
          teardown()
          joinedRef.current = false
          setPhase('removed')
          break
        case 'lock':
          setIsLocked(!!signal.payload.locked)
          break
        case 'role': {
          const uuid = signal.payload.uuid as string
          const role = signal.payload.role as 'host' | 'cohost' | 'participant'
          setRoster((r) => r.map((x) => (x.uuid === uuid ? { ...x, role } : x)))
          if (uuid === user.uuid) setMyRole(role)
          // The old host steps down to co-host wherever they are listed.
          const previous = signal.payload.previous_host as string | undefined
          if (previous) {
            setRoster((r) => r.map((x) => (x.uuid === previous ? { ...x, role: 'cohost' } : x)))
            if (previous === user.uuid) setMyRole('cohost')
          }
          break
        }
        case 'spotlight':
          setSpotlight((signal.payload.uuid as string) ?? null)
          break
        case 'chat':
          setChatMsgs((m) => [...m, {
            from: signal.from_uuid,
            name: signal.from_name ?? 'Someone',
            text: (signal.payload.message as string) ?? '',
            priv: !!signal.payload.private,
            me: false,
            file: signal.payload.file as { uuid: string; name: string; mime: string | null; size: number } | undefined,
          }])
          if (!chatOpenRef.current) setChatUnread((n) => n + 1)
          break
        case 'end':
          teardown()
          joinedRef.current = false
          setPhase('ended')
          break
        case 'offer': {
          try {
            const pc = await createPeer(signal.from_uuid, signal.from_name ?? 'Participant')
            await pc.setRemoteDescription({ type: 'offer', sdp: normalizeSdp(signal.payload.sdp as string) })
            flushPendingIce(signal.from_uuid)
            const answer = await pc.createAnswer()
            await pc.setLocalDescription(answer)
            await meetingsApi.signal(code, 'answer', { sdp: answer.sdp, type: answer.type }, signal.from_uuid)
            if (displayTrackRef.current) {
              meetingsApi.signal(code, 'share', { on: true }, signal.from_uuid).catch(() => undefined)
            }
            if (recorderRef.current) {
              meetingsApi.signal(code, 'record', { on: true }, signal.from_uuid).catch(() => undefined)
            }
            if (!myMediaRef.current.mic || !myMediaRef.current.cam) {
              meetingsApi.signal(code, 'media', myMediaRef.current, signal.from_uuid).catch(() => undefined)
            }
          } catch (err) {
            console.warn('[meeting] offer handling failed', err)
          }
          break
        }
        case 'answer': {
          const pc = pcsRef.current.get(signal.from_uuid)
          if (!pc) return
          try {
            await pc.setRemoteDescription({ type: 'answer', sdp: normalizeSdp(signal.payload.sdp as string) })
            flushPendingIce(signal.from_uuid)
          } catch (err) {
            console.warn('[meeting] answer failed', err)
          }
          break
        }
        case 'ice': {
          const candidate = signal.payload.candidate as RTCIceCandidateInit | undefined
          if (!candidate) return
          const pc = pcsRef.current.get(signal.from_uuid)
          if (pc && pc.remoteDescription) {
            pc.addIceCandidate(candidate).catch(() => undefined)
          } else {
            const q = pendingIceRef.current.get(signal.from_uuid) ?? []
            q.push(candidate)
            pendingIceRef.current.set(signal.from_uuid, q)
          }
          break
        }
      }
    }

    channel.listen('.meeting.signal', handler)
    return () => {
      channel.stopListening('.meeting.signal')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.uuid, code, createPeer, removePeer, teardown, flushPendingIce, showBurst, joinRoom])

  /**
   * Presence ping. The server drops anyone who stops sending this and ends
   * the meeting once the room is empty — which is what makes a crashed tab
   * or a closed laptop behave like a proper "leave".
   */
  useEffect(() => {
    if (phase !== 'in') return
    let stop = false

    const tick = async () => {
      try {
        const hb = await meetingsApi.heartbeat(code)
        if (stop) return
        if (hb.status === 'ended') {
          teardown()
          joinedRef.current = false
          setPhase('ended')
          return
        }
        setRoster(hb.participants.filter((p) => p.uuid !== user?.uuid))
        setIsLocked(!!hb.is_locked)
        setSpotlight(hb.spotlight_uuid ?? null)
        setKnocks(hb.waiting ?? [])
        const mine = hb.participants.find((p) => p.uuid === user?.uuid)
        if (mine) setMyRole(mine.role)
      } catch (err) {
        // 409 means the server already swept us — a laptop lid, a long sleep,
        // a tunnel. Walk back in rather than sit in a room we are no longer in.
        if ((err as { response?: { status?: number } }).response?.status === 409 && !stop) {
          joinedRef.current = false
          void joinRoom()
        }
        /* anything else: one missed beat is fine, the grace window is three */
      }
    }

    void tick()
    const timer = setInterval(tick, 15_000)
    return () => {
      stop = true
      clearInterval(timer)
    }
  }, [phase, code, teardown, user?.uuid, joinRoom])

  /**
   * Closing the tab or navigating away never runs a normal request, so the
   * leave has to go out with keepalive — otherwise the browser cancels it and
   * we become the ghost the reaper has to clean up 45 seconds later.
   */
  useEffect(() => {
    const bye = () => {
      if (!joinedRef.current) return
      const token = useAuthStore.getState().token
      fetch(`/api/v1/meetings/${code}/leave`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        keepalive: true,
      }).catch(() => undefined)
    }
    window.addEventListener('pagehide', bye)
    return () => window.removeEventListener('pagehide', bye)
  }, [code])

  // If the browser blocked media playback (autoplay policy), the first
  // click anywhere resumes every stalled video/audio element.
  useEffect(() => {
    const resume = () => {
      document.querySelectorAll('video, audio').forEach((el) => {
        const media = el as HTMLMediaElement
        if (media.paused && media.srcObject) media.play().catch(() => undefined)
      })
    }
    document.addEventListener('click', resume)
    return () => document.removeEventListener('click', resume)
  }, [])

  // Keep chosen speaker applied as tiles come and go.
  useEffect(() => {
    if (!deviceChoice.speakerId || phase !== 'in') return
    void applySpeaker(deviceChoice.speakerId)
  }, [deviceChoice.speakerId, peers, phase])

  // Track fullscreen so the layout can switch to the split/speaker view.
  useEffect(() => {
    const onFs = () => setIsFs(!!document.fullscreenElement)
    document.addEventListener('fullscreenchange', onFs)
    return () => document.removeEventListener('fullscreenchange', onFs)
  }, [])

  const toggleFullscreen = () => {
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => undefined)
    } else {
      tilesRef.current?.requestFullscreen().catch(() => undefined)
    }
  }

  // Timer.
  useEffect(() => {
    if (phase !== 'in') return
    const t = setInterval(() => setElapsed((s) => s + 1), 1000)
    return () => clearInterval(t)
  }, [phase])

  useEffect(() => {
    return () => {
      pip?.close()
      teardown()
      if (joinedRef.current) meetingsApi.leave(code).catch(() => undefined)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /**
   * Mic/camera state lives in a ref as well as in React state: the signal
   * handler is a long-lived closure, and reading the ref keeps it from
   * broadcasting a stale value for whichever of the two it didn't change.
   */
  const broadcastMedia = useCallback(() => {
    pcsRef.current.forEach((_, uuid) => {
      meetingsApi.signal(code, 'media', myMediaRef.current, uuid).catch(() => undefined)
    })
  }, [code])

  const setMicEnabled = useCallback((on: boolean) => {
    localStreamRef.current?.getAudioTracks().forEach((t) => (t.enabled = on))
    myMediaRef.current = { ...myMediaRef.current, mic: on }
    setMuted(!on)
    broadcastMedia()
  }, [broadcastMedia])

  const setCameraEnabled = useCallback((on: boolean) => {
    localStreamRef.current?.getVideoTracks().forEach((t) => (t.enabled = on))
    if (blurRef.current) blurRef.current.track.enabled = on
    myMediaRef.current = { ...myMediaRef.current, cam: on }
    setCameraOff(!on)
    broadcastMedia()
  }, [broadcastMedia])

  function forceMute(who: string) {
    setMicEnabled(false)
    setUnmuteAsk(null)
    toast(`${who} muted you.`, 'info')
  }

  function forceCameraOff(who: string) {
    setCameraEnabled(false)
    toast(`${who} turned your camera off.`, 'info')
  }

  const toggleMute = () => setMicEnabled(muted)
  const toggleCamera = () => setCameraEnabled(cameraOff)

  /** Flip front/back camera on a phone, or step to the next webcam elsewhere. */
  const flipCamera = async () => {
    if (flipping || sharing) return
    setFlipping(true)
    try {
      const target = nextCamera(cameras, { deviceId: deviceChoice.cameraId, facing: deviceChoice.facing })
      const track = await openCamera(target)
      cameraTrackRef.current = track
      swapTrack(pcsRef.current.values(), localStreamRef.current, track)

      // A background effect is built on the old track — rebuild it on the new
      // one, otherwise the peers keep receiving the camera we just closed.
      const bg = bgChoiceRef.current
      if (bg?.effect) {
        const pipeline = await createEffectTrack(track, bg.effect)
        blurRef.current?.stop()
        blurRef.current = pipeline
        pipeline.track.enabled = !cameraOff
        pcsRef.current.forEach((pc) => {
          const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
          sender?.replaceTrack(pipeline.track).catch(() => undefined)
        })
        showSelf(new MediaStream([pipeline.track]))
      } else {
        showSelf(localStreamRef.current)
      }

      setDeviceChoice(saveDeviceChoice(target))
    } catch (err) {
      toastError('Could not switch camera — it may be in use by another app.')
      console.warn('[meeting] camera flip failed', err)
    } finally {
      setFlipping(false)
    }
  }

  const changeDevice = async (kind: 'camera' | 'mic' | 'speaker', deviceId: string) => {
    try {
      if (kind === 'speaker') {
        setDeviceChoice(saveDeviceChoice({ speakerId: deviceId }))
        await applySpeaker(deviceId)
        return
      }
      if (kind === 'mic') {
        const track = await openMic(deviceId)
        swapTrack(pcsRef.current.values(), localStreamRef.current, track)
        setDeviceChoice(saveDeviceChoice({ micId: deviceId }))
        return
      }
      if (sharing) return
      const track = await openCamera({ deviceId })
      cameraTrackRef.current = track
      swapTrack(pcsRef.current.values(), localStreamRef.current, track)
      showSelf(localStreamRef.current)
      setDeviceChoice(saveDeviceChoice({ cameraId: deviceId }))
    } catch (err) {
      toastError('Could not switch that device — it may be in use by another app.')
      console.warn('[meeting] device switch failed', err)
    }
  }

  /** Screen share: swap the outgoing video track on every peer connection. */
  const toggleShare = async () => {
    if (sharing) {
      stopShare()
      return
    }
    try {
      const display = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true })
      const raw = display.getVideoTracks()[0]
      displayTrackRef.current = raw
      raw.onended = stopShare

      // Draw the camera into a corner of the shared screen and send the
      // composite, so the sharer's face stays on the call. Sending screen and
      // camera as two tracks would need a second transceiver and a
      // renegotiation with every peer; one composited track keeps the
      // connection exactly as it already is.
      const camera = cameraOff ? null : (blurRef.current?.track ?? cameraTrackRef.current ?? null)
      const composite = createSharePipeline(raw, camera)
      sharePipeRef.current = composite
      const track = composite.track

      pcsRef.current.forEach((_, uuid) => {
        meetingsApi.signal(code, 'share', { on: true }, uuid).catch(() => undefined)
      })
      pcsRef.current.forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
        sender?.replaceTrack(track).catch(() => undefined)
      })
      showSelf(new MediaStream([track]))
      setSharing(true)
    } catch {
      /* user cancelled the picker */
    }
  }

  const stopShare = () => {
    const camera = blurRef.current?.track ?? cameraTrackRef.current
    pcsRef.current.forEach((pc) => {
      const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
      if (camera) sender?.replaceTrack(camera).catch(() => undefined)
    })
    sharePipeRef.current?.stop() // stop the compositor before its inputs go
    sharePipeRef.current = null
    displayTrackRef.current?.stop() // release the browser's "sharing your screen" bar
    displayTrackRef.current = null
    pcsRef.current.forEach((_, uuid) => {
      meetingsApi.signal(code, 'share', { on: false }, uuid).catch(() => undefined)
    })
    showSelf(localStreamRef.current)
    setSharing(false)
  }

  const leave = async () => {
    pip?.close()
    teardown()
    joinedRef.current = false
    await meetingsApi.leave(code).catch(() => undefined)
    navigate('/meetings')
  }

  const endForAll = async () => {
    if (!confirm('End this meeting for everyone?')) return
    pip?.close()
    teardown()
    joinedRef.current = false
    await meetingsApi.end(code).catch(() => undefined)
    navigate('/meetings')
  }

  const hostAction = (action: MeetingHostAction, uuid?: string) => {
    meetingsApi.hostAction(code, action, uuid).catch((err) => toastError(errorMessage(err)))
    // Optimistic bits the server confirms on the next heartbeat.
    if (action === 'lock' || action === 'unlock') setIsLocked(action === 'lock')
    if (action === 'spotlight') setSpotlight(uuid ?? null)
    if (action === 'clear_spotlight') setSpotlight(null)
    if (action === 'remove' && uuid) removePeer(uuid)
  }

  const sendReaction = (key: string) => {
    setShowReactions(false)
    if (key === 'hand') {
      const next = !myHand
      setMyHand(next)
      meetingsApi.react(code, next ? 'hand' : 'hand_down').catch(() => undefined)
      return
    }
    showBurst('me', key)
    meetingsApi.react(code, key).catch(() => undefined)
  }

  const sendChat = () => {
    const text = chatDraft.trim()
    if (!text) return
    setChatDraft('')
    setChatMsgs((m) => [...m, { from: 'me', name: 'You', text, priv: !!chatTo, me: true }])
    meetingsApi.chat(code, text, chatTo || null).catch((err) => toastError(errorMessage(err)))
  }

  const broadcastRecord = (on: boolean) => {
    pcsRef.current.forEach((_, uuid) => {
      meetingsApi.signal(code, 'record', { on }, uuid).catch(() => undefined)
    })
  }

  const startRecordingNow = () => {
    if (!tilesRef.current) return
    recorderRef.current = startCompositeRecording({
      container: tilesRef.current,
      audioStreams: () => [
        ...(localStreamRef.current ? [localStreamRef.current] : []),
        ...peers.map((p) => p.stream).filter((s): s is MediaStream => !!s),
      ],
      fileLabel: `netvork-meeting-${code}`,
      onStop: () => setRecording(false),
    })
    setRecording(true)
    broadcastRecord(true)
  }

  startRecRef.current = startRecordingNow

  const toggleRecord = () => {
    if (recording) {
      recorderRef.current?.stop()
      recorderRef.current = null
      setRecording(false)
      broadcastRecord(false)
      return
    }
    // Recording is a HOST right - everyone else asks first.
    if (!canModerate) {
      if (recPending) return
      const hostUuid = roster.find((p) => p.role === 'host')?.uuid ?? meeting?.host.uuid
      if (!hostUuid) return
      setRecPending(true)
      meetingsApi.signal(code, 'rec-request', { ask: 1 }, hostUuid).catch(() => setRecPending(false))
      return
    }
    if (!tilesRef.current) return
    startRecordingNow()
  }

  const applyBackground = async (choice: BackgroundChoice) => {
    if (blurBusy || sharing) return
    // Back to the raw camera first.
    const restore = () => {
      const camera = cameraTrackRef.current
      pcsRef.current.forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
        if (camera) sender?.replaceTrack(camera).catch(() => undefined)
      })
      showSelf(localStreamRef.current)
      blurRef.current?.stop()
      blurRef.current = null
    }
    if (!choice.effect) {
      restore()
      bgChoiceRef.current = null
      setBgHasImage(false)
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
      pcsRef.current.forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
        sender?.replaceTrack(pipeline.track).catch(() => undefined)
      })
      showSelf(new MediaStream([pipeline.track]))
      bgChoiceRef.current = choice
      // Blur has nothing to read, so it can stay mirrored; a picture cannot.
      setBgHasImage(choice.effect.type === 'image')
      setBgLabel(choice.label)
    } catch (err) {
      toastError('Background effect could not start (it needs internet for the model on first use).')
      console.warn('[meeting] background failed', err)
    } finally {
      setBlurBusy(false)
    }
  }

  const sendChatFile = async (file: File) => {
    try {
      const meta = await meetingsApi.chatFile(code, file, chatTo || null)
      setChatMsgs((m) => [...m, { from: 'me', name: 'You', text: '', priv: !!chatTo, me: true, file: meta }])
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  const downloadChatFile = async (fileUuid: string, name: string) => {
    const token = useAuthStore.getState().token
    const res = await fetch(meetingsApi.chatFileUrl(code, fileUuid), { headers: { Authorization: `Bearer ${token}` } })
    if (!res.ok) return toastError('Download failed.')
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
  }

  const changeMyName = () => {
    const name = prompt('Your name for this meeting:', myName ?? user?.name ?? '')
    if (!name?.trim()) return
    meetingsApi.rename(code, name.trim())
      .then(() => setMyName(name.trim()))
      .catch((err) => toastError(errorMessage(err)))
  }

  // --- Picture-in-picture ---------------------------------------------------

  const pipTiles = useMemo(() => [
    ...(hideSelf ? [] : [{ id: 'me', name: 'You', stream: localStreamRef.current, muted: true, speaking: activeSpeaker === 'me' }]),
    ...peers.map((p) => ({ id: p.uuid, name: p.name, stream: p.stream, speaking: activeSpeaker === p.uuid })),
  ], [peers, hideSelf, activeSpeaker])

  useEffect(() => {
    pip?.update(pipTiles)
  }, [pip, pipTiles])

  const togglePip = async () => {
    if (pip) {
      pip.close()
      setPip(null)
      return
    }
    try {
      // Single-video PiP can only float one element, so prefer a remote tile —
      // our own is muted and mirrored, which is the least useful thing to
      // watch while working in another window.
      const videos = [...(tilesRef.current?.querySelectorAll('video') ?? [])] as HTMLVideoElement[]
      const session = await openPip({
        tiles: pipTiles,
        fallbackVideo: videos.find((v) => !v.muted) ?? videos[0] ?? null,
        onClose: () => setPip(null),
      })
      if (!session) {
        toastError('Your browser cannot float this meeting over other windows.')
        return
      }
      setPip(session)
    } catch (err) {
      console.warn('[meeting] pip failed', err)
    }
  }

  const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

  // --- Layout ---------------------------------------------------------------

  const showSelfTile = isVideo && !hideSelf
  const tileCount = peers.length + (showSelfTile ? 1 : 0)

  /** Anyone presenting right now — yourself included. */
  const someoneSharing = sharing || peers.some((p) => p.sharing)

  /** Who gets the big tile: your pin wins, then the host's spotlight, then
   *  whoever is sharing a screen, then whoever is talking. Anyone who has
   *  since left (a spotlight on a departed peer) falls through to nobody. */
  const stageCandidate = pinned
    ?? spotlight
    ?? peers.find((p) => p.sharing)?.uuid
    ?? (sharing ? 'me' : null)
    ?? activeSpeaker
    ?? null
  const stageUuid = stageCandidate === 'me'
    ? (showSelfTile ? 'me' : null)
    : peers.some((p) => p.uuid === stageCandidate) ? stageCandidate : null

  // Column and row sizing live in lib/videoLayout so calls and meetings
  // cannot drift apart again.
  const galleryCols = galleryColumns(tileCount)
  const galleryRows = galleryRows_(tileCount)

  /*
   * Pinning someone means "make this person big", so it has to work from the
   * gallery — which is where everyone is when they reach for it. Requiring a
   * non-gallery layout as well meant pinning from the default view silently
   * did nothing at all.
   *
   * An explicit pin therefore stages on its own, and so does a screen share:
   * the point of presenting is that people read what is on the screen, and a
   * share reduced to one cell of a gallery is unreadable. Both are what every
   * other meeting app does.
   *
   * Spotlight and the automatic pick of whoever is talking still only stage
   * when a staged layout is chosen, so nobody can pull you out of the gallery
   * view you picked just by speaking.
   */
  const stagedLayout = isVideo && stageUuid !== null
    && (layout !== 'gallery' || pinned !== null || someoneSharing)
  const stripSide = layout === 'sidebar'

  const selfTile = showSelfTile ? (
    <div
      key="me"
      className={clsx(
        'relative overflow-hidden rounded-lg bg-slate-900 transition-all',
        activeSpeaker === 'me' && 'ring-2 ring-emerald-400',
        // In the filmstrip the width is fixed, so 16:9 is what sets the
        // height. Everywhere else the tile fills the space the grid or the
        // stage gives it — see galleryRows.
        stagedLayout && stageUuid !== 'me' ? 'aspect-video w-40 shrink-0 sm:w-48' : 'h-full min-h-0',
      )}
    >
      <video
        ref={attachSelf}
        autoPlay
        playsInline
        muted
        className={clsx(
          VIDEO_FIT,
          !sharing && mirrorSelf && '-scale-x-100',
        )}
      />
      {cameraOff && !sharing && (
        <div className="absolute inset-0 flex items-center justify-center bg-slate-900">
          <span className="flex size-12 items-center justify-center rounded-full bg-brand-700 text-lg font-semibold text-white">
            {(myName ?? user?.name ?? 'Y').charAt(0)}
          </span>
        </div>
      )}
      {bursts.me && <span className="absolute right-2 top-2 animate-bounce text-4xl drop-shadow">{REACTIONS[bursts.me]}</span>}
      <button
        className="absolute bottom-1.5 left-1.5 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white hover:bg-black/80"
        title="Change your name for this meeting"
        onClick={changeMyName}
      >
        {myName ?? 'You'}{myRole !== 'participant' && (myRole === 'host' ? ' (Host)' : ' (Co-host)')}
        {myHand && ` ${REACTIONS.hand}`}{sharing && ' (sharing)'} ✎
      </button>
    </div>
  ) : null

  /**
   * Controls that matter but not mid-sentence. Rendered inline on a desktop
   * bar, and inside the overflow sheet on a phone — in exactly one of the two
   * at a time, so their open/closed state never forks.
   */
  const secondaryControls = (
    <>
      <Button
        size="sm"
        variant={recording ? 'danger' : 'secondary'}
        title={recording
          ? 'Stop recording (saves to your Downloads folder)'
          : canModerate
            ? 'Record this meeting — everyone is notified'
            : 'Ask the host for permission to record'}
        onClick={toggleRecord}
        disabled={recPending}
      >
        {recording ? <Square className="size-4" /> : <Circle className="size-4 text-red-500" />}
        {recording ? 'Stop' : recPending ? 'Asking…' : 'Rec'}
      </Button>
      {isVideo && (
        <BackgroundPicker active={bgLabel} disabled={sharing} busy={blurBusy} onPick={applyBackground} />
      )}
      <div className="relative">
        <Button size="sm" variant="secondary" title="Send a reaction" onClick={() => setShowReactions((s) => !s)}>
          <SmilePlus className="size-4" />
        </Button>
        {showReactions && (
          <div className="absolute bottom-11 left-1/2 z-20 flex -translate-x-1/2 gap-1 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900">
            {Object.entries(REACTIONS).filter(([k]) => k !== 'hand').map(([key, emoji]) => (
              <button key={key} className="tap rounded-lg p-1 text-xl hover:bg-slate-100 dark:hover:bg-slate-800" onClick={() => sendReaction(key)}>
                {emoji}
              </button>
            ))}
          </div>
        )}
      </div>
      <Button
        size="sm"
        variant={myHand ? 'primary' : 'secondary'}
        title={myHand ? 'Lower hand' : 'Raise hand'}
        onClick={() => sendReaction('hand')}
      >
        <Hand className="size-4" />
      </Button>

      {/* Layout */}
      {isVideo && (
        <div className="flex overflow-hidden rounded-lg border border-slate-300 dark:border-slate-700">
          {([
            ['gallery', LayoutGrid, 'Gallery — everyone the same size'],
            ['speaker', Rows3, 'Speaker — big stage, strip below'],
            ['sidebar', Grid3x3, 'Sidebar — big stage, strip on the right'],
          ] as const).map(([mode, Icon, title]) => (
            <button
              key={mode}
              title={title}
              className={clsx(
                'tap px-3 py-1.5 sm:px-2',
                layout === mode
                  ? 'bg-brand-600 text-white'
                  : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800',
              )}
              onClick={() => setLayout(mode)}
            >
              <Icon className="size-4" />
            </button>
          ))}
        </div>
      )}

      {pipSupport() && (
        <Button
          size="sm"
          variant={pip ? 'primary' : 'secondary'}
          onClick={togglePip}
          title="Float this meeting over your other apps"
        >
          <PictureInPicture2 className="size-4" />
        </Button>
      )}

      <Button size="sm" variant="secondary" title={isFs ? 'Exit fullscreen' : 'Fullscreen'} onClick={toggleFullscreen}>
        <Expand className="size-4" />
      </Button>

      <div className="relative">
        <Button size="sm" variant={showSettings ? 'primary' : 'secondary'} title="Devices & view" onClick={() => setShowSettings((s) => !s)}>
          <Settings2 className="size-4" />
        </Button>
        {showSettings && (
          <DeviceMenu
            choice={deviceChoice}
            audioOnly={!isVideo}
            hideSelf={hideSelf}
            onHideSelf={setHideSelf}
            mirror={mirror}
            mirrorSuppressed={bgHasImage}
            onMirror={(v) => {
              setMirror(v)
              setDeviceChoice(saveDeviceChoice({ mirror: v }))
            }}
            onChange={changeDevice}
            onClose={() => setShowSettings(false)}
          />
        )}
      </div>
    </>
  )

  const peerTile = (p: Peer, onStage: boolean) => (
    <PeerTile
      key={p.uuid}
      peer={p}
      video={isVideo}
      burst={bursts[p.uuid]}
      hand={hands.has(p.uuid) || !!rosterMap.get(p.uuid)?.hand_raised}
      role={rosterMap.get(p.uuid)?.role}
      active={activeSpeaker === p.uuid}
      spotlight={spotlight === p.uuid}
      pinned={pinned === p.uuid}
      quality={quality[p.uuid]}
      onContextMenu={(e) => {
        e.preventDefault()
        setTileMenu({ uuid: p.uuid, name: p.name, x: e.clientX, y: e.clientY })
      }}
      className={clsx(
        isVideo && (stagedLayout && !onStage ? 'aspect-video w-40 shrink-0 sm:w-48' : 'h-full min-h-0'),
      )}
    />
  )

  // --- Phases ---------------------------------------------------------------

  if (phase === 'loading') {
    return <Card className="mx-auto mt-10 max-w-md text-center text-sm text-slate-400">Opening the meeting…</Card>
  }

  if (phase === 'lobby') {
    return (
      <MeetingLobby
        title={meeting?.title ?? 'Meeting'}
        hostName={meeting?.is_host ? undefined : meeting?.host.name}
        needsPasscode={!!meeting?.has_passcode && !meeting?.can_moderate}
        defaultName={user?.name ?? 'Guest'}
        audioOnly={!isVideo}
        error={lobbyError}
        onJoin={(result) => {
          setLobbyError(null)
          void joinRoom(result)
        }}
        onCancel={() => navigate('/meetings')}
      />
    )
  }

  if (phase === 'waiting') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">Asking the host to let you in…</p>
        <p className="mt-1 text-xs text-slate-400">
          The host has a waiting room on. You will join automatically the moment they admit you —
          keep this page open.
        </p>
        <Button className="mt-4" variant="secondary" onClick={() => navigate('/meetings')}>Cancel</Button>
      </Card>
    )
  }

  if (phase === 'denied' || phase === 'removed') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">
          {phase === 'removed' ? 'You were removed from this meeting' : 'The host did not admit you'}
        </p>
        <Button className="mt-4" onClick={() => navigate('/meetings')}>Back to Meetings</Button>
      </Card>
    )
  }

  if (phase === 'error' || phase === 'ended') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">{phase === 'ended' ? 'Meeting ended' : 'Could not join'}</p>
        <p className="mt-1 text-xs text-slate-400">
          {phase === 'ended' ? 'Everyone has left, or the host ended it.' : errorMsg}
        </p>
        <Button className="mt-4" onClick={() => navigate('/meetings')}>Back to Meetings</Button>
      </Card>
    )
  }

  return (
    <div className="flex h-full flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-base font-semibold">{meeting?.title ?? 'Meeting'}</h1>
          <p className="flex flex-wrap items-center gap-2 text-xs text-slate-400">
            <span className="font-mono">{code}</span>
            {phase === 'in' ? <span className="text-emerald-600">{fmt(elapsed)}</span> : 'Connecting…'}
            <span className="flex items-center gap-1"><Users className="size-3" /> {peers.length + 1}</span>
            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              You: {myRole === 'host' ? 'host' : myRole === 'cohost' ? 'co-host' : 'participant'}
            </span>
            {isLocked && (
              <span className="flex items-center gap-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-950 dark:text-red-300">
                <Lock className="size-3" /> Locked
              </span>
            )}
            {meeting?.passcode && (
              <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                Passcode: <span className="font-mono">{meeting.passcode}</span>
              </span>
            )}
            {canModerate && approvalOn !== null && (
              <button
                className="flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 hover:text-brand-600 dark:bg-slate-800 dark:text-slate-400"
                title={approvalOn ? 'Waiting room ON — click for open access' : 'Open access — click to require your approval'}
                onClick={() => {
                  const next = !approvalOn
                  meetingsApi.setApproval(code, next).then(() => setApprovalOn(next)).catch((err) => toastError(errorMessage(err)))
                }}
              >
                {approvalOn ? <Lock className="size-3" /> : <LockOpen className="size-3" />}
                {approvalOn ? 'Approval required' : 'Open access'}
              </button>
            )}
          </p>
          {canModerate && knocks.length > 0 && (
            <div className="mt-1.5 space-y-1">
              {knocks.map((k) => (
                <div key={k.uuid} className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs dark:border-amber-900 dark:bg-amber-950">
                  <span className="font-medium">{k.name}</span> wants to join
                  <Button
                    size="sm"
                    onClick={() => {
                      meetingsApi.admit(code, k.uuid, true).catch(() => undefined)
                      setKnocks((ks) => ks.filter((x) => x.uuid !== k.uuid))
                    }}
                  >
                    Admit
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                      meetingsApi.admit(code, k.uuid, false).catch(() => undefined)
                      setKnocks((ks) => ks.filter((x) => x.uuid !== k.uuid))
                    }}
                  >
                    Deny
                  </Button>
                </div>
              ))}
            </div>
          )}
        </div>
        <Button
          size="sm"
          variant="secondary"
          onClick={() =>
            navigator.clipboard.writeText(meetingLink(code)).then(
              () => {
                setCopied(true)
                setTimeout(() => setCopied(false), 2000)
              },
              () => prompt('Copy this link:', meetingLink(code)),
            )
          }
        >
          <Copy className="size-3.5" /> {copied ? 'Copied ✓' : 'Copy invite link'}
        </Button>
      </div>

      {unmuteAsk && (
        <div className="flex items-center gap-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs dark:border-brand-900 dark:bg-brand-950">
          <span className="font-medium">{unmuteAsk}</span> is asking you to unmute.
          <Button size="sm" onClick={() => { setMicEnabled(true); setUnmuteAsk(null) }}>Unmute</Button>
          <Button size="sm" variant="secondary" onClick={() => setUnmuteAsk(null)}>Stay muted</Button>
        </div>
      )}

      {canModerate && recRequests.length > 0 && (
        <div className="space-y-1">
          {recRequests.map((r) => (
            <div key={r.uuid} className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs dark:border-red-900 dark:bg-red-950">
              <span className="font-medium">{r.name}</span> wants to record this meeting
              <Button size="sm" onClick={() => {
                meetingsApi.signal(code, 'rec-allow', { ok: 1 }, r.uuid).catch(() => undefined)
                setRecRequests((rs) => rs.filter((x) => x.uuid !== r.uuid))
              }}>
                Allow
              </Button>
              <Button size="sm" variant="secondary" onClick={() => {
                meetingsApi.signal(code, 'rec-deny', { ok: 0 }, r.uuid).catch(() => undefined)
                setRecRequests((rs) => rs.filter((x) => x.uuid !== r.uuid))
              }}>
                Deny
              </Button>
            </div>
          ))}
        </div>
      )}

      {(recording || recorders.length > 0) && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          <span className="relative flex size-2">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75" />
            <span className="relative inline-flex size-2 rounded-full bg-red-600" />
          </span>
          Recording in progress{recording && ' — you are recording'}
          {recorders.length > 0 && ` — ${recorders.join(', ')} ${recorders.length === 1 ? 'is' : 'are'} recording`}
        </div>
      )}

      <div className="flex min-h-0 flex-1 flex-col gap-2 sm:flex-row">
        {/* Tiles */}
        <div
          ref={tilesRef}
          className={clsx(
            // Tiles are 16:9, so two rows of them can be taller than the space
            // left over — without a scroll container they spilled out of the
            // flex child and painted straight over the control bar.
            'scroll-pane relative min-h-0 flex-1 overflow-y-auto overscroll-contain bg-slate-950/0',
            isFs && 'bg-slate-950 p-3',
            stagedLayout
              ? stripSide ? 'flex gap-2' : 'flex flex-col gap-2'
              // content-start is gone on purpose: it packed the rows against
              // the top at their natural height, which is what let them run
              // past the bottom of the grid.
              : isVideo
                ? clsx('grid gap-2', galleryCols, galleryRows)
                : clsx('grid content-start gap-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3'),
          )}
        >
          {stagedLayout ? (
            <>
              {/* Stage */}
              <div className={clsx('min-h-0 min-w-0 flex-1', stripSide ? 'order-first' : '')}>
                {stageUuid === 'me' && selfTile}
                {peers.filter((p) => p.uuid === stageUuid).map((p) => peerTile(p, true))}
              </div>
              {/* Filmstrip */}
              <div className={clsx('flex gap-2', stripSide ? 'w-40 flex-col overflow-y-auto sm:w-48' : 'overflow-x-auto pb-1')}>
                {stageUuid !== 'me' && selfTile}
                {peers.filter((p) => p.uuid !== stageUuid).map((p) => peerTile(p, false))}
              </div>
            </>
          ) : (
            <>
              {selfTile}
              {peers.map((p) => peerTile(p, false))}
              {/*
                Floated over the grid rather than placed in it. As a grid child
                this took a row of its own, so the only person in the room got
                half the height — cropped to a letterbox slot with the other
                half sitting empty.
              */}
              {!peers.length && phase === 'in' && (
                <p className="pointer-events-none absolute inset-x-0 bottom-2 text-center text-sm text-slate-400 drop-shadow">
                  Waiting for others — share the invite link.
                </p>
              )}
              {!isVideo && (
                <button
                  className="flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700"
                  title="Change your name for this meeting"
                  onClick={changeMyName}
                >
                  <span className="flex size-7 items-center justify-center rounded-full bg-emerald-600 text-xs font-semibold">
                    {(myName ?? user?.name)?.charAt(0) ?? 'Y'}
                  </span>
                  {myName ?? 'You'} {muted && '(muted)'} ✎
                </button>
              )}
            </>
          )}
        </div>

        {showPeople && (
          <ParticipantsPanel
            me={me}
            participants={roster}
            canModerate={canModerate}
            isHost={isHost}
            isLocked={isLocked}
            spotlightUuid={spotlight}
            pinnedUuid={pinned}
            quality={quality}
            onAction={hostAction}
            onPin={setPinned}
            onClose={() => setShowPeople(false)}
          />
        )}
      </div>

      {tileMenu && (
        <>
          {/* Click anywhere (or press Escape, below) to dismiss. */}
          <div className="fixed inset-0 z-40" onMouseDown={() => setTileMenu(null)} onContextMenu={(e) => { e.preventDefault(); setTileMenu(null) }} />
          <div
            className="fixed z-50 min-w-44 rounded-xl border border-slate-200 bg-white p-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900"
            style={{
              // Kept inside the window — a right-click near the right or
              // bottom edge would otherwise open the menu off-screen.
              left: Math.min(tileMenu.x, window.innerWidth - 190),
              top: Math.min(tileMenu.y, window.innerHeight - 90),
            }}
          >
            <p className="truncate px-2 py-1 text-[11px] uppercase tracking-wide text-slate-400">{tileMenu.name}</p>
            <button
              className="tap flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-slate-100 dark:hover:bg-slate-800"
              onClick={() => {
                setPinned(pinned === tileMenu.uuid ? null : tileMenu.uuid)
                setTileMenu(null)
              }}
            >
              {pinned === tileMenu.uuid
                ? <><PinOff className="size-4" /> Unpin</>
                : <><Pin className="size-4" /> Pin for me</>}
            </button>
            <p className="px-2 pb-1 pt-0.5 text-[11px] text-slate-400">Only changes your view.</p>
          </div>
        </>
      )}

      {chatOpen && (
        <div className="flex max-h-64 flex-col rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
          <div className="flex-1 space-y-1 overflow-y-auto">
            {!chatMsgs.length ? (
              <p className="text-center text-xs text-slate-400">No messages yet — say hello. Chat disappears when the meeting ends.</p>
            ) : (
              chatMsgs.map((m, i) => (
                <p key={i} className="text-sm">
                  <span className={m.me ? 'font-semibold text-brand-600' : 'font-semibold'}>{m.name}</span>
                  {m.priv && <span className="ml-1 rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">private</span>}
                  {m.text && <span className="ml-1.5">{m.text}</span>}
                  {m.file && (
                    <button
                      className="ml-1.5 inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-0.5 text-xs text-brand-600 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                      onClick={() => downloadChatFile(m.file!.uuid, m.file!.name)}
                      title={`Download (${Math.max(1, Math.round(m.file.size / 1024))} KB)`}
                    >
                      <Paperclip className="size-3" /> {m.file.name}
                    </button>
                  )}
                </p>
              ))
            )}
          </div>
          <div className="mt-2 flex gap-1.5">
            <select
              className="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-slate-700 dark:bg-slate-900"
              value={chatTo}
              onChange={(e) => setChatTo(e.target.value)}
            >
              <option value="">To everyone</option>
              {peers.map((p) => (
                <option key={p.uuid} value={p.uuid}>Privately to {p.name}</option>
              ))}
            </select>
            <input
              className="flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900"
              placeholder={chatTo ? 'Private message…' : 'Message everyone…'}
              value={chatDraft}
              onChange={(e) => setChatDraft(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && sendChat()}
            />
            <Button size="sm" variant="secondary" title="Share a file or image (max 10 MB)" onClick={() => chatFileRef.current?.click()}>
              <Paperclip className="size-3.5" />
            </Button>
            <input
              ref={chatFileRef}
              type="file"
              className="hidden"
              onChange={(e) => {
                const f = e.target.files?.[0]
                e.target.value = ''
                if (f) sendChatFile(f)
              }}
            />
            <Button size="sm" onClick={sendChat} disabled={!chatDraft.trim()}>Send</Button>
          </div>
        </div>
      )}

      {/* Controls. On a phone only the ones you reach for mid-sentence stay
          on the bar; the rest move into a sheet, because eighteen buttons
          wrap into four rows at 375px and cover the faces. */}
      <div className="pb-safe flex flex-wrap items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white p-2 dark:border-slate-800 dark:bg-slate-900 sm:gap-2 sm:p-3">
        <Button size="sm" variant="secondary" onClick={toggleMute} title={muted ? 'Unmute' : 'Mute'}>
          {muted ? <MicOff className="size-4 text-red-500" /> : <Mic className="size-4" />}
        </Button>
        {isVideo && (
          <>
            <Button size="sm" variant="secondary" onClick={toggleCamera} title="Toggle camera">
              {cameraOff ? <VideoOff className="size-4 text-red-500" /> : <Video className="size-4" />}
            </Button>
            <Button
              size="sm"
              variant="secondary"
              onClick={flipCamera}
              disabled={flipping || sharing}
              title={sharing ? 'Stop sharing to switch camera' : 'Switch camera (front/back)'}
            >
              <SwitchCamera className={clsx('size-4', flipping && 'animate-spin')} />
            </Button>
            {/* Screen sharing from a phone browser is not a thing — getDisplayMedia
                is unsupported on mobile Safari and Android Chrome. */}
            {!isPhone && (
              <Button size="sm" variant={sharing ? 'primary' : 'secondary'} onClick={toggleShare} title={sharing ? 'Stop sharing' : 'Share screen'}>
                <MonitorUp className="size-4" />
              </Button>
            )}
          </>
        )}
        {!isPhone && secondaryControls}
        <div className="relative">
          <Button
            size="sm"
            variant={showPeople ? 'primary' : 'secondary'}
            title="Participants"
            onClick={() => setShowPeople((s) => !s)}
          >
            <Users className="size-4" /> {peers.length + 1}
          </Button>
          {knocks.length > 0 && canModerate && !showPeople && (
            <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">
              {knocks.length}
            </span>
          )}
        </div>
        <div className="relative">
          <Button size="sm" variant={chatOpen ? 'primary' : 'secondary'} title="Meeting chat" onClick={() => { setChatOpen(!chatOpen); setChatUnread(0) }}>
            <MessageSquare className="size-4" />
          </Button>
          {chatUnread > 0 && !chatOpen && (
            <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
              {chatUnread}
            </span>
          )}
        </div>
        {isPhone && (
          <Button size="sm" variant={moreOpen ? 'primary' : 'secondary'} title="More options" onClick={() => setMoreOpen((s) => !s)}>
            <MoreHorizontal className="size-4" />
          </Button>
        )}
        <Button size="sm" variant="danger" onClick={leave}>
          <PhoneOff className="size-4" /> <span className="hidden sm:inline">Leave</span>
        </Button>
        {isHost && !isPhone && (
          <Button size="sm" variant="danger" onClick={endForAll}>
            End for all
          </Button>
        )}
      </div>

      {/* Overflow sheet (phones only) */}
      {isPhone && moreOpen && (
        <div className="fixed inset-0 z-50 flex items-end bg-black/40" onMouseDown={(e) => e.target === e.currentTarget && setMoreOpen(false)}>
          <div className="pb-safe w-full rounded-t-2xl border-t border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
            <div className="mb-2 flex items-center justify-between">
              <p className="text-sm font-semibold">Meeting options</p>
              <button className="tap rounded-lg px-2 text-xs text-slate-400" onClick={() => setMoreOpen(false)}>Done</button>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {isVideo && (
                <Button size="sm" variant={sharing ? 'primary' : 'secondary'} onClick={toggleShare} title="Share screen">
                  <MonitorUp className="size-4" /> Share
                </Button>
              )}
              {secondaryControls}
              {isHost && (
                <Button size="sm" variant="danger" onClick={endForAll}>End for all</Button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/** Mid-meeting device switcher — the same choices as the lobby, live. */
function DeviceMenu({
  choice, audioOnly, hideSelf, onHideSelf, mirror, mirrorSuppressed, onMirror, onChange, onClose,
}: {
  choice: DeviceChoice
  audioOnly: boolean
  hideSelf: boolean
  onHideSelf: (v: boolean) => void
  mirror: boolean
  /** A picture background overrides the preference — text has to read correctly. */
  mirrorSuppressed: boolean
  onMirror: (v: boolean) => void
  onChange: (kind: 'camera' | 'mic' | 'speaker', deviceId: string) => void
  onClose: () => void
}) {
  const { cameras, mics, speakers } = useDevices()

  return (
    <div
      className="absolute bottom-11 right-0 z-30 w-64 space-y-2 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-lg dark:border-slate-700 dark:bg-slate-900"
      onMouseLeave={onClose}
    >
      {!audioOnly && (
        <label className="block">
          <span className="mb-1 block font-medium text-slate-500">Camera</span>
          <select
            className="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900"
            value={choice.cameraId ?? ''}
            onChange={(e) => e.target.value && onChange('camera', e.target.value)}
          >
            <option value="">Default camera</option>
            {cameras.map((c) => <option key={c.deviceId} value={c.deviceId}>{c.label}</option>)}
          </select>
        </label>
      )}
      <label className="block">
        <span className="mb-1 block font-medium text-slate-500">Microphone</span>
        <select
          className="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900"
          value={choice.micId ?? ''}
          onChange={(e) => e.target.value && onChange('mic', e.target.value)}
        >
          <option value="">Default microphone</option>
          {mics.map((m) => <option key={m.deviceId} value={m.deviceId}>{m.label}</option>)}
        </select>
      </label>
      {speakerSelectionSupported() && (
        <label className="block">
          <span className="mb-1 block font-medium text-slate-500">Speaker</span>
          <select
            className="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900"
            value={choice.speakerId ?? ''}
            onChange={(e) => e.target.value && onChange('speaker', e.target.value)}
          >
            <option value="">Default speaker</option>
            {speakers.map((s) => <option key={s.deviceId} value={s.deviceId}>{s.label}</option>)}
          </select>
        </label>
      )}
      {!audioOnly && (
        <>
          <label className="flex items-center gap-2 pt-1">
            <input type="checkbox" checked={hideSelf} onChange={(e) => onHideSelf(e.target.checked)} />
            <User className="size-3.5" /> Hide my own tile
          </label>
          <label className={clsx('flex items-center gap-2', mirrorSuppressed && 'opacity-50')}>
            <input
              type="checkbox"
              checked={mirror && !mirrorSuppressed}
              disabled={mirrorSuppressed}
              onChange={(e) => onMirror(e.target.checked)}
            />
            <FlipHorizontal className="size-3.5" /> Mirror my own view
          </label>
          <p className="text-[11px] leading-snug text-slate-400">
            {mirrorSuppressed
              ? 'Off while you have a picture background — mirroring would show its text backwards. Only you ever saw the flip; others always see you the right way round.'
              : 'Affects your tile only. Everyone else always sees you unmirrored.'}
          </p>
        </>
      )}
    </div>
  )
}
