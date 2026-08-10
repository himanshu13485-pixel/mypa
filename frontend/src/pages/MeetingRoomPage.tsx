import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Circle, Copy, Expand, FlipHorizontal, Grid3x3, Hand, Hourglass, KeyRound, LayoutGrid, Lock, LockOpen, MessageSquare,
  Mic, MicOff, MonitorUp, MoreHorizontal, Paperclip, PhoneOff, PictureInPicture2, Pin, PinOff, Rows3,
  Settings2, SmilePlus, Square, SwitchCamera, User, Users, Video, VideoOff, Volume2,
} from 'lucide-react'
import { clsx } from 'clsx'
import { calls, meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { getEcho } from '../lib/echo'
import { startCompositeRecording, type CompositeRecorder } from '../lib/recorder'
import { createEffectTrack, createSharePipeline, type BlurPipeline } from '../lib/videoFx'
import { VIDEO_FIT, useGalleryLayout, useSelfView } from '../lib/videoLayout'
import { useActiveSpeaker } from '../lib/activeSpeaker'
import { usePeerQuality } from '../lib/netQuality'
import {
  AUDIO_CONSTRAINTS, VIDEO_CONSTRAINTS, applySpeaker, loadDeviceChoice, nextCamera, openCamera, openMic,
  applySendQuality, saveDeviceChoice, screenShareSupported, senderFor, shareFailureMessage, speakerSelectionSupported,
  swapTrack, testSpeaker, useDevices, useMicLevel, type DeviceChoice,
} from '../lib/devices'
import { keepScreenAwake, openPip, pipSupport, type PipSession } from '../lib/pip'
import {
  enterFullscreen, exitFullscreen, fullscreenElement, fullscreenSupported, onFullscreenChange,
} from '../lib/fullscreen'
import { useIsPhone, useLandscapePhone } from '../lib/useMediaQuery'
import BackgroundPicker, { type BackgroundChoice } from '../components/BackgroundPicker'
import MeetingLobby, { type LobbyResult } from '../components/MeetingLobby'
import ParticipantsPanel, { QualityDot } from '../components/ParticipantsPanel'
import { useAuthStore } from '../stores/auth'
import { readGuestPass } from '../lib/guestPass'
import { useToast } from '../components/Toast'
import { Button, Card } from '../components/ui'
import { NEW_MEETING, meetingLink } from './MeetingsPage'
import type { MeetingHostAction, MeetingParticipant, MeetingSignalPayload } from '../types'
import { normalizeSdp } from '../lib/sdp'
import { Avatar } from '../lib/avatars'
import { usePrompt } from '../components/Prompt'

const REACTIONS: Record<string, string> = {
  thumbsup: '\u{1F44D}', clap: '\u{1F44F}', heart: '\u{2764}\u{FE0F}', laugh: '\u{1F602}',
  wow: '\u{1F62E}', party: '\u{1F389}', yes: '\u{2705}', no: '\u{274C}',
  slower: '\u{1F422}', faster: '\u{1F407}', hand: '\u{270B}',
}

type Layout = 'gallery' | 'speaker' | 'sidebar'

interface Peer {
  uuid: string
  name: string
  /** From the meeting roster, so a camera-off tile still shows a face. */
  avatar?: string | null
  stream: MediaStream | null
  sharing?: boolean
  micOff?: boolean
  camOff?: boolean
  /** ICE state, surfaced on the tile until the media path is established. */
  conn?: string
}

function PeerTile({
  peer, video, burst, hand, role, active, spotlight, pinned, quality, className, style, onContextMenu, onDoubleClick,
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
  style?: React.CSSProperties
  onContextMenu?: (e: React.MouseEvent) => void
  onDoubleClick?: (e: React.MouseEvent) => void
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
        <Avatar name={peer.name} avatar={peer.avatar} size={28} />
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
      onDoubleClick={onDoubleClick}
      style={style}
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
          <Avatar name={peer.name} avatar={peer.avatar} size={52} />
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
  const { code: routeCode = '' } = useParams()
  /**
   * A meeting that does not exist yet.
   *
   * "New meeting" used to create the room and then show the lobby, so anyone
   * who looked at their camera and thought better of it left a meeting behind
   * that they had never held. Pressing the button now only opens the lobby;
   * the room is created at the moment somebody actually walks into it, which
   * is the moment it becomes a real thing.
   *
   * Everything below goes on using `code` exactly as before — it simply starts
   * out as the placeholder and becomes the real code on join.
   */
  const [createdCode, setCreatedCode] = useState<string | null>(null)
  const code = createdCode ?? routeCode
  const unborn = code === NEW_MEETING
  const navigate = useNavigate()
  const account = useAuthStore((s) => s.user)
  /*
   * Who this browser is in the room.
   *
   * A guest has no account, but the room needs a uuid and a name for exactly
   * the same things a member does — the self tile, the roster, and the
   * tie-break that decides which side sends the WebRTC offer. Reading the
   * pass here means none of that code has to know the difference.
   */
  const guestPass = useMemo(() => readGuestPass(), [])
  const isGuest = !account && guestPass?.code === code
  const user = useMemo(
    () => account ?? (isGuest ? { uuid: guestPass!.uuid, name: guestPass!.name } : null),
    [account, isGuest, guestPass],
  )
  const { toast, toastError } = useToast()
  const { ask, confirm } = usePrompt()

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
  /** The room including its controls — what fullscreen should cover. */
  const roomRef = useRef<HTMLDivElement>(null)
  const [knocks, setKnocks] = useState<{ uuid: string; name: string }[]>([])
  const [approvalOn, setApprovalOn] = useState<boolean | null>(null)
  /**
   * The meeting password, which is also the guest switch: with one set the
   * ordinary invite link admits people who have no account and type it.
   * Local because the host can change it from in here, and undefined until the
   * meeting has loaded so "no password" and "not known yet" stay distinct.
   */
  const [roomPasscode, setRoomPasscode] = useState<string | null | undefined>(undefined)
  /** Bumped whenever the outgoing mic track is replaced — see changeDevice. */
  const [micRev, setMicRev] = useState(0)
  /**
   * The plan's ceilings, as the room sees them.
   *
   * expiresAt comes back on every heartbeat rather than being computed once,
   * because it can move: a host who upgrades mid-meeting lifts the limit, and
   * the countdown should follow rather than keep threatening.
   */
  const [expiresAt, setExpiresAt] = useState<string | null>(null)
  const [endedReason, setEndedReason] = useState<'time_limit' | null>(null)
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
  /** Offers in flight, so a slow one is not dialled a second time. */
  const dialingRef = useRef<Set<string>>(new Set())
  /** When each offer went out — an answer that never comes is repaired. */
  const dialedAtRef = useRef<Map<string, number>>(new Map())
  const wakeLockRef = useRef<{ release: () => void } | null>(null)

  const { data: meeting } = useQuery({
    queryKey: ['meeting', code],
    queryFn: () => meetingsApi.show(code),
    retry: false,
    // Nothing to look up until it exists.
    enabled: !unborn,
  })
  const isVideo = meeting?.type !== 'audio'
  const canModerate = myRole === 'host' || myRole === 'cohost'
  // Only moderators are sent the actual password; everyone else gets null and
  // never sees this control at all.
  useEffect(() => {
    if (meeting) setRoomPasscode(meeting.passcode ?? null)
  }, [meeting])
  const minutesLimit = meeting?.minutes_limit ?? null
  const participantLimit = meeting?.participant_limit ?? null
  /** Seconds left, or null when the meeting has no time limit. */
  const secondsLeft = useMemo(() => {
    if (!expiresAt) return null
    return Math.max(0, Math.round((new Date(expiresAt).getTime() - Date.now()) / 1000))
    // elapsed ticks once a second, which is exactly the recompute we want.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [expiresAt, elapsed])
  const isHost = myRole === 'host'

  const isPhone = useIsPhone()
  /** Constant for the life of the page — the browser does not grow the API. */
  const canShareScreen = useMemo(screenShareSupported, [])
  const canFullscreen = useMemo(fullscreenSupported, [])
  /** Sideways on a phone: the picture gets the room's own chrome as well. */
  const landscape = useLandscapePhone()
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

    /*
     * Join with the camera off and the camera stays shut.
     *
     * This used to open it either way and then set track.enabled = false,
     * which stops the picture but holds the device — so somebody who turned
     * their camera off in the lobby watched the light come on by itself the
     * moment they were let in, next to a button still showing it as off.
     */
    const wantVideo = meeting?.type !== 'audio' && lobbyRef.current?.camOn !== false

    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio,
        video: wantVideo ? video : false,
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

    // Carry the lobby's mic choice into the room. The microphone is left open
    // and muted rather than released: unmuting has to be instant, and a track
    // that is not enabled sends nothing.
    const wanted = lobbyRef.current
    if (wanted) {
      stream.getAudioTracks().forEach((t) => (t.enabled = wanted.micOn))
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

      /*
       * A place for the camera, even when it is off.
       *
       * Joining with the camera off means there is no video track to add, and
       * nothing here handles renegotiation — so without a transceiver reserved
       * up front there would be no sender to put a camera into later, and
       * turning it on mid-meeting would reach nobody.
       *
       * The slot has to name the stream. A transceiver added without one puts
       * no msid in the offer, so the far side received the track with no
       * stream attached to it and never showed the picture — the camera came
       * on here and stayed black over there for the rest of the meeting.
       */
      if (meeting?.type !== 'audio' && !stream.getVideoTracks().length) {
        pc.addTransceiver('video', { direction: 'sendrecv', streams: [stream] })
      }

      // Sized for the room, not for this one connection — see applySendQuality.
      // pcsRef already holds this peer, so the count includes them.
      applySendQuality([pc], pcsRef.current.size)

      setPeers((p) => (p.some((x) => x.uuid === peerUuid) ? p : [...p, { uuid: peerUuid, name: peerName, stream: null }]))

      /*
       * What this peer is sending us, as it stands right now.
       *
       * Taken from the receivers rather than collected from track events as
       * they arrive. A renegotiation hands out fresh tracks and the old ones
       * linger, and a stream carrying a dead video track ahead of the live
       * one plays the dead one — a black tile with every sign of a healthy
       * connection.
       *
       * A new stream object each time is the point: the tile re-attaches when
       * the identity changes, and a stream altered in place would not repaint.
       */
      const refreshStream = () => {
        const live = pc.getReceivers()
          .map((r) => r.track)
          .filter((t): t is MediaStreamTrack => !!t && t.readyState === 'live')
        const video = live.filter((t) => t.kind === 'video')
        /*
         * A video element plays the first video track it is handed, so a slot
         * that is carrying nothing must not be the one it picks. Renegotiation
         * can leave more than one, and choosing wrongly looks exactly like a
         * broken connection. Before frames start they are all silent, and that
         * is fine — the unmute below comes back through here.
         */
        const showing = video.some((t) => !t.muted) ? video.filter((t) => !t.muted) : video
        const tracks = [...live.filter((t) => t.kind !== 'video'), ...showing]
        setPeers((p) => p.map((x) => (x.uuid === peerUuid ? { ...x, stream: new MediaStream(tracks) } : x)))
      }

      pc.ontrack = (event) => {
        console.info('[meeting] track from', peerUuid, event.track.kind, event.track.muted ? 'muted' : 'live')
        /*
         * A track arrives muted when the camera at the far end is not on yet,
         * and the picture starting later is not a new track — it is this one
         * unmuting. Nothing was listening for that, so an element that
         * attached a silent track kept showing black until the page was
         * reloaded and it attached a stream already running. That is what the
         * reloads were for.
         */
        event.track.onunmute = refreshStream
        event.track.onended = refreshStream
        refreshStream()
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
    [code, ensureLocalStream, restartIce, meeting?.type],
  )

  /**
   * Ring somebody and offer them our media.
   *
   * Used on the way in, and again by the heartbeat for anyone we ought to be
   * connected to but are not.
   */
  const offerTo = useCallback(async (peerUuid: string, peerName: string) => {
    if (dialingRef.current.has(peerUuid)) return
    dialingRef.current.add(peerUuid)
    try {
      const pc = await createPeer(peerUuid, peerName)
      const offer = await pc.createOffer()
      await pc.setLocalDescription(offer)
      await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, peerUuid)
      dialedAtRef.current.set(peerUuid, Date.now())
      // An offer says nothing about mute, and only the answering side sends
      // its own state back — so a lobby mute has to be announced here, or
      // the room shows it a heartbeat late. The mirror of the same call in
      // the offer handler.
      if (!myMediaRef.current.mic || !myMediaRef.current.cam) {
        meetingsApi.signal(code, 'media', myMediaRef.current, peerUuid).catch(() => undefined)
      }
    } finally {
      dialingRef.current.delete(peerUuid)
    }
  }, [code, createPeer])

  /**
   * Offer again, because what we send has changed shape.
   *
   * replaceTrack is enough only when the slot it goes into was negotiated to
   * send. A camera that arrives after the connection was made does not always
   * get that: the far end settled the direction of that slot while it was
   * empty, and no amount of replacing a track says otherwise. The picture then
   * never left this machine, and reloading was the only thing that helped — a
   * reload is a renegotiation, just an expensive one that takes the whole
   * meeting with it.
   *
   * Saying sendrecv out loud costs nothing when it is already true.
   */
  const renegotiateWith = useCallback(async (peerUuid: string, why: string) => {
    const pc = pcsRef.current.get(peerUuid)
    if (!pc) return
    /*
     * Wait for the line to clear instead of giving up on it.
     *
     * A connection busy negotiating used to be skipped outright, and nothing
     * ever came back to it — the camera coming on is a moment, and by the time
     * the line was free that moment had passed. Connections are at their
     * busiest in the first seconds of a meeting, which is exactly when someone
     * who left their camera off in the lobby turns it on, so the one case that
     * needed this most was the one that reliably missed it.
     */
    for (let wait = 0; pc.signalingState !== 'stable' && wait < 10; wait++) {
      await new Promise((resolve) => setTimeout(resolve, 1000))
      if (pcsRef.current.get(peerUuid) !== pc) return // torn down while waiting
    }
    if (pc.signalingState !== 'stable') {
      console.warn('[meeting] gave up renegotiating with', peerUuid, pc.signalingState)
      return
    }
    try {
      const tx = pc.getTransceivers()
        .find((t) => (t.sender.track?.kind ?? t.receiver.track?.kind) === 'video')
      if (tx && tx.direction !== 'sendrecv') tx.direction = 'sendrecv'
      const offer = await pc.createOffer()
      await pc.setLocalDescription(offer)
      await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, peerUuid)
      dialedAtRef.current.set(peerUuid, Date.now())
      console.info('[meeting] renegotiated with', peerUuid, why)
    } catch (err) {
      console.warn('[meeting] renegotiation failed', peerUuid, err)
    }
  }, [code])

  /** Everyone at once — one slow peer must not hold up the rest. */
  const renegotiate = useCallback(async (why: string) => {
    await Promise.all([...pcsRef.current.keys()].map((uuid) => renegotiateWith(uuid, why)))
  }, [renegotiateWith])

  const removePeer = useCallback((peerUuid: string) => {
    pcsRef.current.get(peerUuid)?.close()
    pcsRef.current.delete(peerUuid)
    dialedAtRef.current.delete(peerUuid)
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

      // The room is made here, not when the button was pressed — so backing
      // out of the lobby leaves nothing behind. Local because everything below
      // in this call needs the real code before the re-render delivers it.
      let live = code
      if (live === NEW_MEETING) {
        const made = await meetingsApi.create({ type: 'video' })
        live = made.code
        setCreatedCode(made.code)
        // Replace rather than push: Back should return to the meetings list,
        // not to a placeholder URL for a room that now exists.
        navigate(`/meetings/room/${made.code}`, { replace: true })
      }

      const info = await meetingsApi.join(live, {
        ...(opts?.displayName ? { display_name: opts.displayName } : {}),
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
      // Straight away, rather than waiting up to 15s for the first heartbeat
      // — a 40-minute meeting should not start by claiming to be untimed.
      setExpiresAt(room.expires_at ?? null)
      if (opts) {
        setMuted(!opts.micOn)
        setCameraOff(!opts.camOn)
        myMediaRef.current = { mic: opts.micOn, cam: opts.camOn }
        if (opts.displayName) setMyName(opts.displayName)
      }
      setRoster(room.joined_peers ?? [])
      keepScreenAwake().then((lock) => (wakeLockRef.current = lock))

      // One peer we cannot reach must not cost us the others: a throw here
      // used to abandon everybody further down the list and drop the room
      // into the error screen. The heartbeat picks up whatever failed.
      // offerTo signals with `code`, which is still the placeholder on the
      // render that creates the room. Safe: a meeting that did not exist a
      // moment ago has nobody in it, so this loop is empty in exactly the case
      // where the two differ.
      for (const peer of room.joined_peers ?? []) {
        await offerTo(peer.uuid, peer.name).catch((err) => {
          console.warn('[meeting] could not offer to', peer.uuid, err)
        })
      }
    } catch (err) {
      const status = (err as { response?: { status?: number } }).response?.status
      const message = errorMessage(err)
      // 403 (removed, or a co-host restriction) and 423 (locked) are things
      // that may resolve while they wait, so send them back to the lobby with
      // the reason rather than to a dead end.
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
  }, [code, offerTo, ensureLocalStream, navigate])

  // Show the lobby once the meeting is loaded. Screen-share codes belong to
  // the Screen module - send them there instead of a meeting room.
  useEffect(() => {
    // A meeting that does not exist yet has nothing to load: straight to the
    // lobby, and it is created if and when they decide to walk in.
    if (unborn) {
      if (phase === 'loading') setPhase('lobby')
      return
    }
    if (!meeting || phase !== 'loading') return
    if (meeting.is_screen) {
      navigate(`/screen/session/${code}`, { replace: true })
      return
    }
    setPhase('lobby')
  }, [meeting, navigate, code, phase, unborn])

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
            /*
             * Two offers can now cross, since either side re-offers when its
             * camera arrives. The lower uuid wins — the tie-break used
             * everywhere else here. The other takes its own offer back,
             * answers this one, and asks again once the line is clear.
             */
            let yielded = false
            if (pc.signalingState !== 'stable') {
              if ((user.uuid ?? '') < signal.from_uuid) {
                console.info('[meeting] ignoring colliding offer from', signal.from_uuid)
                return
              }
              await pc.setLocalDescription({ type: 'rollback' })
              yielded = true
            }
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
            // Whatever we rolled back to answer this has not been said yet.
            if (yielded) void renegotiate('after giving way')
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
  }, [user?.uuid, code, createPeer, removePeer, teardown, flushPendingIce, showBurst, joinRoom, renegotiate])

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
          if (hb.ended_reason === 'time_limit') setEndedReason('time_limit')
          setPhase('ended')
          return
        }
        // The deadline can move: the host upgrading mid-meeting lifts it.
        setExpiresAt(hb.expires_at ?? null)
        const others = hb.participants.filter((p) => p.uuid !== user?.uuid)
        setRoster(others)
        /*
         * The roster is the authority on who is called what.
         *
         * A tile takes its name from whichever signal introduced that peer,
         * which is a single message that can be wrong, late or missing — and
         * when it was wrong, the name stayed wrong for the whole meeting. The
         * heartbeat carries every name, straight from the participant rows, so
         * anything that has drifted is put right within one beat.
         */
        setPeers((ps) => {
          const roster = new Map(others.map((p) => [p.uuid, p]))
          const stale = ps.some((x) => {
            const row = roster.get(x.uuid)
            return row && (row.name !== x.name || (row.avatar ?? null) !== (x.avatar ?? null))
          })
          return stale
            ? ps.map((x) => {
                const row = roster.get(x.uuid)
                return row ? { ...x, name: row.name, avatar: row.avatar ?? null } : x
              })
            : ps
        })
        /*
         * Anyone we ought to be talking to but are not.
         *
         * A connection was made once, when somebody walked in, and nothing
         * ever looked at it again — no renegotiation, no retry. So a single
         * offer or answer that went missing left a black tile for the rest of
         * the meeting, and reloading the page was the only way to repair it.
         * That is what the refreshes were doing.
         *
         * The roster says who is in the room and the peer map says who we
         * actually reached; the difference is dialled again here. It does not
         * matter why a connection is missing — lost answer, two people
         * arriving in the same instant, a throttled signal — the gap looks
         * the same from here and is closed the same way.
         *
         * When neither side has anything, both see the same gap at the same
         * moment, so only one may dial or the offers collide — the lower uuid
         * goes, the same arbitrary tie-break an ICE restart uses. A connection
         * we can see is broken from here is different: the other end may think
         * it is perfectly well, and waiting for it to act would be waiting for
         * ever. Whoever can see the damage repairs it, and a collision, if it
         * comes to that, is handled where offers are answered.
         */
        for (const peer of others) {
          if (!user?.uuid) continue
          const pc = pcsRef.current.get(peer.uuid)
          if (dialingRef.current.has(peer.uuid)) continue
          // An offer sent long enough ago that an answer should have come
          // back. Fresh ones are left alone, since the first beat runs
          // immediately and would otherwise cut off a normal join.
          const unanswered = pc?.signalingState === 'have-local-offer'
            && Date.now() - (dialedAtRef.current.get(peer.uuid) ?? 0) > 10_000
          const broken = !!pc && ['failed', 'closed'].includes(pc.connectionState)
          if (pc && !unanswered && !broken) continue
          if (!pc && user.uuid > peer.uuid) continue
          console.info('[meeting] redialling', peer.uuid, pc ? pc.connectionState : 'never connected')
          // Start from nothing: a half-negotiated connection cannot be
          // offered on, and createPeer hands back whatever it already has.
          if (pc) removePeer(peer.uuid)
          void offerTo(peer.uuid, peer.name).catch(() => undefined)
        }
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
  }, [phase, code, teardown, user?.uuid, joinRoom, offerTo, removePeer])

  /**
   * Closing the tab or navigating away never runs a normal request, so the
   * leave has to go out with keepalive — otherwise the browser cancels it and
   * we become the ghost the reaper has to clean up 45 seconds later.
   */
  useEffect(() => {
    const bye = () => {
      if (!joinedRef.current) return
      // Raw fetch, so the api client's interceptor is not here to swap a guest
      // onto their own routes: without this a guest left on `Bearer null` and
      // stayed in the room as a frozen tile until the reaper took them.
      const token = useAuthStore.getState().token
      const pass = token ? null : readGuestPass()
      const path = pass ? `/api/v1/guest/meetings/${code}/leave` : `/api/v1/meetings/${code}/leave`
      const bearer = token ?? pass?.token
      if (!bearer) return
      fetch(path, {
        method: 'POST',
        headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' },
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
    const onFs = () => setIsFs(!!fullscreenElement())
    return onFullscreenChange(onFs)
  }, [])

  const toggleFullscreen = async () => {
    if (fullscreenElement()) {
      await exitFullscreen()
    } else {
      /*
       * The whole room, not just the tiles.
       *
       * Fullscreening the tiles container left every control outside the
       * fullscreen element, so mute, camera, chat, participants and leave all
       * disappeared the moment you pressed it — on a phone, where the button
       * is easy to hit by accident, with no visible way back but Escape, which
       * a phone does not have. Calls were fixed this way; meetings never were.
       */
      await enterFullscreen(roomRef.current)
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
    /*
     * Nothing to enable: the camera was never opened, because it was off when
     * this meeting was joined. Open it now and put it into the sender that was
     * reserved for exactly this.
     */
    if (on && !localStreamRef.current?.getVideoTracks().length) {
      void (async () => {
        try {
          const track = await openCamera({
            deviceId: deviceChoice.cameraId,
            facing: deviceChoice.facing,
          })
          cameraTrackRef.current = track
          swapTrack(pcsRef.current.values(), localStreamRef.current, track)
          track.enabled = true

          // A background chosen before the camera went off is put back, rather
          // than silently dropping to the bare camera on the way in.
          const bg = bgChoiceRef.current
          if (bg?.effect) {
            try {
              const pipeline = await createEffectTrack(track, bg.effect)
              blurRef.current?.stop()
              blurRef.current = pipeline
              pcsRef.current.forEach((pc) => {
                senderFor(pc, 'video')?.replaceTrack(pipeline.track).catch(() => undefined)
              })
              showSelf(new MediaStream([pipeline.track]))
            } catch {
              showSelf(localStreamRef.current)
            }
          } else if (localStreamRef.current) {
            showSelf(localStreamRef.current)
          }

          myMediaRef.current = { ...myMediaRef.current, cam: true }
          setCameraOff(false)
          broadcastMedia()
          // The slot this went into was negotiated while it was empty, and may
          // not have been agreed as one we can send on. Settle that now.
          void renegotiate('camera on')
        } catch {
          toastError('Could not start the camera — it may be in use by another app or tab.')
        }
      })()
      return
    }

    /*
     * Switching the camera off lets go of it, rather than muting it.
     *
     * Disabling a track stops the picture but holds the device, so the light
     * stayed on for the rest of the meeting next to a button showing the
     * camera as off. The sender is emptied rather than removed, so the slot
     * survives for the camera to be put back into — nothing here renegotiates.
     *
     * Not while sharing a screen: what the sender carries then is the
     * composite, which is drawn from the camera and would break under it. That
     * camera goes when the share ends, or is released by this on the next
     * press.
     */
    if (!on && !sharing && localStreamRef.current?.getVideoTracks().length) {
      const stream = localStreamRef.current
      blurRef.current?.stop()
      blurRef.current = null
      pcsRef.current.forEach((pc) => {
        senderFor(pc, 'video')?.replaceTrack(null).catch(() => undefined)
      })
      stream.getVideoTracks().forEach((t) => {
        t.stop()
        stream.removeTrack(t)
      })
      cameraTrackRef.current = null
      showSelf(stream)
      myMediaRef.current = { ...myMediaRef.current, cam: false }
      setCameraOff(true)
      broadcastMedia()
      return
    }

    localStreamRef.current?.getVideoTracks().forEach((t) => (t.enabled = on))
    if (blurRef.current) blurRef.current.track.enabled = on
    myMediaRef.current = { ...myMediaRef.current, cam: on }
    setCameraOff(!on)
    broadcastMedia()
  }, [broadcastMedia, deviceChoice.cameraId, deviceChoice.facing, sharing, showSelf, toastError, renegotiate])

  function forceMute(who: string) {
    setMicEnabled(false)
    setUnmuteAsk(null)
    toast(`${who} muted you.`, 'info')
  }

  function forceCameraOff(who: string) {
    setCameraEnabled(false)
    toast(`${who} turned your camera off.`, 'info')
  }

  /**
   * Re-size every outgoing stream whenever the room changes size.
   *
   * Doing it only when a connection is made would size the newcomer's stream
   * correctly and leave everyone already talking still sending as though the
   * room were empty — which is exactly the case that overloads an uplink,
   * because it is the people who have been there longest who have the most
   * connections to feed.
   */
  useEffect(() => {
    if (phase !== 'in') return
    const quality = applySendQuality(pcsRef.current.values(), pcsRef.current.size)
    console.info('[meeting] sending', quality.label, 'to', pcsRef.current.size, 'peer(s)')
  }, [peers.length, phase])

  /** Stable across renders, so the meter's memo only re-runs on micRev. */
  const getLocalStream = useCallback(() => localStreamRef.current, [])

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
          const sender = senderFor(pc, 'video')
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

  /**
   * Set or change the meeting password — the one control that decides whether
   * the invite link works for people without an account.
   */
  const changePasscode = async () => {
    const next = await ask({
      title: roomPasscode ? 'Change the meeting password' : 'Add a meeting password',
      message:
        'Anyone without a Netvork account is asked for this on the way in, and stays 30 minutes. '
        + 'Signed-in members never need it. 4–12 letters or digits.',
      value: roomPasscode ?? '',
      placeholder: 'e.g. open1234',
      actionLabel: 'Save',
    })
    if (next === null) return

    const clean = next.replace(/[^a-zA-Z0-9]/g, '')
    if (clean.length < 4) {
      toastError('A password needs at least 4 letters or digits.')
      return
    }

    try {
      const res = await meetingsApi.setPasscode(code, clean)
      setRoomPasscode(res.data.passcode)
      toast(res.message, 'success')
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  /**
   * Let somebody in, or turn them away.
   *
   * The row goes the moment it is clicked, because the host has decided and
   * the answer should not wait on a round trip. But if the server refuses,
   * that has to be said: this used to discard the error and put the row back
   * on the next heartbeat, so admitting a guest — which the server was
   * rejecting outright — looked like a click that simply did nothing, over
   * and over.
   */
  const decide = async (who: { uuid: string; name: string }, allow: boolean) => {
    setKnocks((ks) => ks.filter((x) => x.uuid !== who.uuid))
    try {
      await meetingsApi.admit(code, who.uuid, allow)
    } catch (err) {
      toastError(`Could not let ${who.name} in — ${errorMessage(err)}`)
      setKnocks((ks) => (ks.some((x) => x.uuid === who.uuid) ? ks : [...ks, who]))
    }
  }

  /** Take the password off, which also shuts the door on guests. */
  const clearPasscode = async () => {
    const sure = await confirm({
      title: 'Remove the password?',
      message: 'Anyone without a Netvork account will no longer be able to join with the link. '
        + 'People already in the meeting stay.',
      actionLabel: 'Remove it',
      danger: true,
    })
    if (!sure) return

    try {
      const res = await meetingsApi.setPasscode(code, null)
      setRoomPasscode(null)
      toast(res.message, 'success')
    } catch (err) {
      toastError(errorMessage(err))
    }
  }

  /**
   * Swap a camera, microphone or speaker without leaving the meeting.
   *
   * An empty deviceId is "whatever the system says" — the entry has to be
   * selectable, or picking a specific device once would be permanent for the
   * rest of the meeting.
   */
  const changeDevice = async (kind: 'camera' | 'mic' | 'speaker', deviceId: string) => {
    const id = deviceId || undefined
    try {
      if (kind === 'speaker') {
        setDeviceChoice(saveDeviceChoice({ speakerId: id }))
        // Back to the system default: there is no sink id for that, and the
        // empty string is what setSinkId defines as "undo".
        await applySpeaker(id ?? '')
        return
      }
      if (kind === 'mic') {
        const track = await openMic(id)
        swapTrack(pcsRef.current.values(), localStreamRef.current, track)
        setDeviceChoice(saveDeviceChoice({ micId: id }))
        // The stream object is mutated in place, so nothing downstream would
        // otherwise notice the track underneath it changed.
        setMicRev((n) => n + 1)
        toast(myMediaRef.current.mic ? 'Microphone switched.' : 'Microphone switched — you are still muted.', 'success')
        return
      }
      if (sharing) return
      const track = await openCamera({ deviceId: id })
      cameraTrackRef.current = track
      swapTrack(pcsRef.current.values(), localStreamRef.current, track)
      showSelf(localStreamRef.current)
      setDeviceChoice(saveDeviceChoice({ cameraId: id }))
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
        const sender = senderFor(pc, 'video')
        sender?.replaceTrack(track).catch(() => undefined)
      })
      // The composite needs no new transceiver, but it does need the one it
      // goes into to be agreed as ours to send on — which it is not, for
      // anyone who joined with the camera off and never turned it on.
      void renegotiate('screen share')
      showSelf(new MediaStream([track]))
      setSharing(true)
    } catch (err) {
      // A bare catch here meant a browser that cannot share a screen at all
      // behaved identically to one where you changed your mind: nothing
      // happened, and nothing said why.
      const message = shareFailureMessage(err)
      if (message) {
        toastError(message)
        console.warn('[meeting] screen share failed', err)
      }
    }
  }

  const stopShare = () => {
    const camera = blurRef.current?.track ?? cameraTrackRef.current
    pcsRef.current.forEach((pc) => {
      // Emptied when there is no camera to go back to, rather than left
      // holding the composite that is about to be stopped — otherwise peers
      // keep the last frame of the shared screen frozen on the tile.
      senderFor(pc, 'video')?.replaceTrack(camera ?? null).catch(() => undefined)
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
    const sure = await confirm({
      title: 'End this meeting for everyone?',
      message: 'Everybody is disconnected, not just you. To step out on your own, use Leave instead.',
      actionLabel: 'End for everyone',
      danger: true,
    })
    if (!sure) return
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
        const sender = senderFor(pc, 'video')
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
        const sender = senderFor(pc, 'video')
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
    // Files are the one part of the chat a guest does not get — there is no
    // guest route for them. Say which it is, rather than "Download failed."
    if (!token) {
      return toastError('Files in a meeting need a Netvork account. Ask whoever shared it to send it another way.')
    }
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

  const changeMyName = async () => {
    const name = await ask({ title: 'Your name in this meeting', value: myName ?? user?.name ?? '', actionLabel: 'Rename' })
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

  // Measured in lib/videoLayout, so the arrangement follows the space that
  // exists rather than the width of the window.
  const gallery = useGalleryLayout(tileCount)
  /* One ref feeding two things: picture-in-picture reads the tiles out of it,
     and the layout measures it. Stable, so it is not detached every render. */
  const attachGallery = gallery.attach
  const setTilesEl = useCallback((el: HTMLDivElement | null) => {
    tilesRef.current = el
    attachGallery(el)
  }, [attachGallery])

  /** Explicit size for a gallery tile; empty until the box is measured. */
  const galleryTileStyle = gallery.width
    ? { width: gallery.width, height: gallery.height }
    : undefined

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
        // stage gives it — see useGalleryLayout.
        stagedLayout
          ? stageUuid === 'me' ? 'h-full min-h-0' : 'aspect-video h-full shrink-0'
          : 'min-h-0 shrink-0',
      )}
      style={stagedLayout ? undefined : galleryTileStyle}
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
          <Avatar
            name={myName ?? user?.name ?? 'You'}
            photoPath={account?.profile?.photo_path}
            avatar={account?.profile?.avatar}
            gender={account?.profile?.gender}
            size={52}
          />
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

      {/* Absent on iPhone, which has no fullscreen for anything but a bare
          video element — turning the phone sideways is what gets you the whole
          picture there, and that already happens by itself. */}
      {canFullscreen && (
        <Button size="sm" variant="secondary" title={isFs ? 'Exit fullscreen' : 'Fullscreen'} onClick={toggleFullscreen}>
          <Expand className="size-4" />
        </Button>
      )}

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
            micStream={getLocalStream}
            micRev={micRev}
          />
        )}
      </div>
    </>
  )

  /*
   * The mic and camera badges come from the roster, not from whichever `media`
   * signal happened to arrive.
   *
   * A `media` signal is only sent when somebody toggles, and at connect time
   * only the answering side sends one — so anyone who joined already muted, or
   * with the camera off, showed up to everyone already in the room as though
   * both were on, for the rest of the meeting. Which tiles were wrong depended
   * on join order, so host, member and guest each saw a different room.
   *
   * The server records mic_on/cam_on when a person joins and on every toggle
   * after that, and the heartbeat carries them, so the roster knows about
   * state that was set before we were listening. Signals still write to the
   * roster, so a toggle is as instant as it ever was.
   */
  const peerTile = (p: Peer, onStage: boolean) => {
    const row = rosterMap.get(p.uuid)
    const peer = row ? { ...p, micOff: !row.mic_on, camOff: !row.cam_on } : p

    return (
      <PeerTile
        key={p.uuid}
        peer={peer}
        video={isVideo}
        burst={bursts[p.uuid]}
        hand={hands.has(p.uuid) || !!row?.hand_raised}
        role={row?.role}
        active={activeSpeaker === p.uuid}
        spotlight={spotlight === p.uuid}
        pinned={pinned === p.uuid}
        quality={quality[p.uuid]}
        onContextMenu={(e) => {
          e.preventDefault()
          setTileMenu({ uuid: p.uuid, name: p.name, x: e.clientX, y: e.clientY })
        }}
        // Right-click is not a gesture a phone has. Double-tap does the same
        // thing the menu's Pin does, and only changes your own view.
        onDoubleClick={() => setPinned(pinned === p.uuid ? null : p.uuid)}
        className={clsx(
          isVideo && (stagedLayout
            ? onStage ? 'h-full min-h-0' : 'aspect-video h-full shrink-0'
            : 'min-h-0 shrink-0'),
        )}
        style={isVideo && !stagedLayout ? galleryTileStyle : undefined}
      />
    )
  }

  // --- Phases ---------------------------------------------------------------

  if (phase === 'loading') {
    return <Card className="mx-auto mt-10 max-w-md text-center text-sm text-slate-400">Opening the meeting…</Card>
  }

  if (phase === 'lobby') {
    return (
      <MeetingLobby
        title={meeting?.title ?? 'Meeting'}
        hostName={meeting?.is_host ? undefined : meeting?.host.name}
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
        <p className="text-sm font-semibold">
          {phase !== 'ended' ? 'Could not join' : endedReason === 'time_limit' ? 'Time is up' : 'Meeting ended'}
        </p>
        <p className="mt-1 text-xs text-slate-400">
          {phase !== 'ended'
            ? errorMsg
            : endedReason === 'time_limit'
              ? `This meeting reached the ${minutesLimit ? `${minutesLimit}-minute ` : ''}limit on the host's plan. `
                + 'Starting another one carries on from where you left off.'
              : 'Everyone has left, or the host ended it.'}
        </p>
        <Button className="mt-4" onClick={() => navigate('/meetings')}>Back to Meetings</Button>
      </Card>
    )
  }

  return (
    <div ref={roomRef} className={clsx('flex h-full flex-col bg-slate-100 dark:bg-slate-950', landscape ? 'gap-1' : 'gap-3')}>
      {/* Sideways on a phone the title, code and passcode are a third of the
          picture. The controls stay; everything else can wait for portrait. */}
      <div className={clsx('flex-wrap items-center justify-between gap-2', landscape ? 'hidden' : 'flex')}>
        {/* Shrinkable, so the invite button can sit beside it rather than
            taking a third row of a phone screen away from the faces. */}
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-base font-semibold">{meeting?.title ?? 'Meeting'}</h1>
          <p className="flex flex-wrap items-center gap-2 text-xs text-slate-400">
            <span className="font-mono">{code}</span>
            {phase === 'in' ? <span className="text-emerald-600">{fmt(elapsed)}</span> : 'Connecting…'}
            <span
              className="flex items-center gap-1"
              title={participantLimit ? `The host's plan allows ${participantLimit} people at once` : undefined}
            >
              <Users className="size-3" /> {peers.length + 1}{participantLimit ? ` / ${participantLimit}` : ''}
            </span>
            {/* Counting down only near the end. A timer running the whole way
                through makes a meeting feel like an exam; five minutes out it
                is information you can act on. */}
            {secondsLeft !== null && secondsLeft <= 5 * 60 && (
              <span
                className={clsx(
                  'flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold',
                  secondsLeft <= 60
                    ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                    : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                )}
                title={minutesLimit ? `The host's plan allows ${minutesLimit} minutes per meeting` : undefined}
              >
                <Hourglass className="size-3" />
                {fmt(secondsLeft)} left
              </span>
            )}
            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              You: {myRole === 'host' ? 'host' : myRole === 'cohost' ? 'co-host' : 'participant'}
            </span>
            {isLocked && (
              <span className="flex items-center gap-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-950 dark:text-red-300">
                <Lock className="size-3" /> Locked
              </span>
            )}
            {/* The password is the guest switch, and the instant "New meeting"
                button makes none — so in here is the only place a host can
                reach it. Participants see whether there is one, never what. */}
            {canModerate && roomPasscode !== undefined ? (
              <span className="flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <KeyRound className="size-3" />
                {roomPasscode ? (
                  <>Password: <span className="select-all font-mono">{roomPasscode}</span></>
                ) : (
                  'Members only'
                )}
                <button className="hover:text-brand-600" onClick={changePasscode}>
                  {roomPasscode ? 'change' : 'add a password'}
                </button>
                {roomPasscode && (
                  <button className="hover:text-red-600" onClick={clearPasscode}>remove</button>
                )}
              </span>
            ) : meeting?.has_passcode ? (
              <span className="flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <KeyRound className="size-3" /> Password set
              </span>
            ) : null}
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
                  <Button size="sm" onClick={() => void decide(k, true)}>Admit</Button>
                  <Button size="sm" variant="secondary" onClick={() => void decide(k, false)}>
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
          title="Copy invite link"
          onClick={() =>
            navigator.clipboard.writeText(meetingLink(code)).then(
              () => {
                setCopied(true)
                setTimeout(() => setCopied(false), 2000)
              },
              () => void ask({ title: 'Invite link', message: 'Copy this and send it to whoever should join.', value: meetingLink(code), readOnly: true, actionLabel: 'Done' }),
            )
          }
        >
          <Copy className="size-3.5" />
          <span className="hidden sm:inline">{copied ? 'Copied ✓' : 'Copy invite link'}</span>
          <span className="sm:hidden">{copied ? '✓' : ''}</span>
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
          ref={setTilesEl}
          className={clsx(
            // Tiles are 16:9, so two rows of them can be taller than the space
            // left over — without a scroll container they spilled out of the
            // flex child and painted straight over the control bar.
            'scroll-pane relative min-h-0 flex-1 overflow-y-auto overscroll-contain bg-slate-950/0',
            isFs && 'bg-slate-950 p-3',
            stagedLayout
              ? stripSide ? 'flex gap-2' : 'flex flex-col gap-2'
              : isVideo
                // Centred wrapping row, sized from the measurement above, so a
                // short last row sits in the middle instead of hanging off the
                // left edge. CSS grid packs it left and cannot be talked out
                // of it.
                ? 'flex flex-wrap content-center items-center justify-center gap-2'
                : 'grid content-start gap-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
          )}
        >
          {stagedLayout ? (
            <>
              {/* Filmstrip. Above the stage in the stacked layout — that is
                  where every other meeting app puts it — and beside it in the
                  sidebar layout, which is the point of that mode. */}
              <div
                className={clsx(
                  'flex shrink-0 gap-2',
                  stripSide ? 'order-last w-40 flex-col overflow-y-auto sm:w-48' : 'h-24 justify-center overflow-x-auto sm:h-28',
                )}
              >
                {stageUuid !== 'me' && selfTile}
                {peers.filter((p) => p.uuid !== stageUuid).map((p) => peerTile(p, false))}
              </div>
              {/* Stage */}
              <div className="min-h-0 min-w-0 flex-1">
                {stageUuid === 'me' && selfTile}
                {peers.filter((p) => p.uuid === stageUuid).map((p) => peerTile(p, true))}
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
                <p className="pointer-events-none absolute inset-x-0 top-3 text-center text-sm text-slate-400 drop-shadow">
                  {/* Along the top, not the bottom: a tile that fills the
                      height puts its name label exactly where this used to sit,
                      and the two printed over each other. */}
                  <span className="rounded-full bg-black/45 px-3 py-1 text-white">
                    Waiting for others — share the invite link.
                  </span>
                </p>
              )}
              {!isVideo && (
                <button
                  className="flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700"
                  title="Change your name for this meeting"
                  onClick={changeMyName}
                >
                  <Avatar
                    name={myName ?? user?.name}
                    photoPath={account?.profile?.photo_path}
                    avatar={account?.profile?.avatar}
                    gender={account?.profile?.gender}
                    size={28}
                  />
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
            {/* Sharing a file is a members-only part of the panel — there is
                no guest route behind it — so a guest is not offered a button
                that could only fail. Typing still works for everybody. */}
            {!isGuest && (
              <Button size="sm" variant="secondary" title="Share a file or image (max 10 MB)" onClick={() => chatFileRef.current?.click()}>
                <Paperclip className="size-3.5" />
              </Button>
            )}
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
            {/* Whether the browser can, not whether the window is narrow — a
                desktop dragged small keeps its button, and a phone that never
                could is not offered one that does nothing. */}
            {canShareScreen && (
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
              {/* This sheet used to offer Share unconditionally while the
                  toolbar above hid it on phones — so the only place a phone
                  could reach it was the one place it could never work. */}
              {isVideo && canShareScreen && (
                <Button size="sm" variant={sharing ? 'primary' : 'secondary'} onClick={toggleShare} title="Share screen">
                  <MonitorUp className="size-4" /> Share
                </Button>
              )}
              {isVideo && !canShareScreen && (
                // Saying nothing would leave someone hunting for a button that
                // is not there. This is a browser limit, not a setting they
                // have missed, so the note says so and offers the way round it.
                <p className="w-full text-[11px] leading-snug text-slate-400">
                  <MonitorUp className="mr-1 inline size-3" />
                  Screen sharing needs a computer — no phone browser can capture its own screen.
                  Join from a laptop to share, or use the camera switch to point the back camera at
                  whatever you want people to see.
                </p>
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

/**
 * Mid-meeting device switcher — the same choices as the lobby, live.
 *
 * Changing microphone or speaker halfway through is the common case, not the
 * exotic one: headphones come off, a headset gets plugged in, the laptop lid
 * shuts and the meeting moves to a monitor. Each change takes effect on the
 * spot (replaceTrack for the mic, setSinkId for the speaker) with no
 * renegotiation and nobody dropping out.
 */
function DeviceMenu({
  choice, audioOnly, hideSelf, onHideSelf, mirror, mirrorSuppressed, onMirror, onChange, onClose,
  micStream, micRev,
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
  /** The live outgoing stream, for the level meter. */
  micStream: () => MediaStream | null
  /** Bumped on every mic swap — swapTrack mutates the stream in place, so
      without this the meter would keep watching the track we just stopped. */
  micRev: number
}) {
  const { cameras, mics, speakers } = useDevices()
  const [testing, setTesting] = useState(false)
  const meterStream = useMemo(() => {
    const s = micStream()
    return s ? new MediaStream(s.getAudioTracks()) : null
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [micStream, micRev])
  const level = useMicLevel(meterStream)

  const select = 'w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900'

  return (
    // A phone has no hover, so leaving is not a gesture it can make: the
    // backdrop is what closes this there. On a desktop it is invisible and
    // catches the click-away.
    <>
      <div className="fixed inset-0 z-20" onMouseDown={onClose} />
      <div
        className="absolute bottom-11 right-0 z-30 max-h-[70vh] w-64 max-w-[calc(100vw-2rem)] space-y-2 overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-lg dark:border-slate-700 dark:bg-slate-900"
      >
        {!audioOnly && (
          <label className="block">
            <span className="mb-1 block font-medium text-slate-500">Camera</span>
            <select
              className={select}
              value={choice.cameraId ?? ''}
              onChange={(e) => onChange('camera', e.target.value)}
            >
              <option value="">Default camera</option>
              {cameras.map((c) => <option key={c.deviceId} value={c.deviceId}>{c.label}</option>)}
            </select>
          </label>
        )}

        <label className="block">
          <span className="mb-1 block font-medium text-slate-500">Microphone</span>
          <select
            className={select}
            value={choice.micId ?? ''}
            onChange={(e) => onChange('mic', e.target.value)}
          >
            <option value="">Default microphone</option>
            {mics.map((m) => <option key={m.deviceId} value={m.deviceId}>{m.label}</option>)}
          </select>
        </label>

        {/* Proof the one you just picked is the one hearing you — otherwise
            switching mic mid-meeting is a guess you only settle by asking
            everyone whether they can still hear you. */}
        <div className="flex items-center gap-2">
          <Mic className="size-3 shrink-0 text-slate-400" />
          <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
            <div
              className="h-full rounded-full bg-emerald-500 transition-[width] duration-75"
              style={{ width: `${Math.round(level * 100)}%` }}
            />
          </div>
        </div>

        {speakerSelectionSupported() ? (
          <label className="block">
            <span className="mb-1 block font-medium text-slate-500">Speaker</span>
            <select
              className={select}
              value={choice.speakerId ?? ''}
              onChange={(e) => onChange('speaker', e.target.value)}
            >
              <option value="">Default speaker</option>
              {speakers.map((s) => <option key={s.deviceId} value={s.deviceId}>{s.label}</option>)}
            </select>
            <button
              className="mt-1 flex items-center gap-1 text-[11px] text-slate-400 hover:text-brand-600"
              disabled={testing}
              onClick={async (e) => {
                e.preventDefault()
                setTesting(true)
                await testSpeaker(choice.speakerId)
                setTesting(false)
              }}
            >
              <Volume2 className="size-3" /> {testing ? 'Playing…' : 'Play a test sound'}
            </button>
          </label>
        ) : (
          // Safari and Firefox have no setSinkId: the browser follows the
          // system output and there is nothing to offer here but the truth.
          <p className="text-[11px] leading-snug text-slate-400">
            Sound goes to whichever output your device is set to — this browser
            gives no way to choose one per meeting. Change it in your system
            sound settings and it follows immediately.
          </p>
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
    </>
  )
}
