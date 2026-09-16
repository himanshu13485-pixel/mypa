import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { MonitorOff, MonitorUp } from 'lucide-react'
import { calls, meetings as meetingsApi } from '../api/endpoints'
import { getEcho } from '../lib/echo'
import { readGuestPass } from '../lib/guestPass'
import { useAuthStore } from '../stores/auth'
import { normalizeSdp } from '../lib/sdp'
import { shareFailureMessage } from '../lib/devices'
import { useSelfView } from '../lib/videoLayout'
import { Button } from './ui'
import type { MeetingItem, MeetingSignalPayload } from '../types'
import { livePath } from '../lib/crmPath'

export interface ScreenViewer {
  uuid: string
  name: string
}

export interface ScreenChatLine {
  name: string
  text: string
  priv: boolean
  me: boolean
}

/** 'idle' is nothing running; the rest are the session page's own screens. */
export type ScreenPhase = 'idle' | 'starting' | 'waiting' | 'denied' | 'live' | 'ended' | 'error'

interface ScreenShareContextValue {
  /** The session being run, '' when there is none. */
  code: string
  session: MeetingItem | undefined
  loadError: unknown
  isHost: boolean
  phase: ScreenPhase
  errorMsg: string
  viewers: ScreenViewer[]
  knocks: ScreenViewer[]
  approvalOn: boolean | null
  paused: boolean
  showPreview: boolean
  viewerMuted: boolean
  /**
   * Host only: which surface the browser's picker actually handed over.
   * 'browser' is one tab; 'window' and 'monitor' are the others; null means
   * nothing is being captured, or the browser does not say. What the host
   * chose changes what viewers get, so the page has to be able to tell them.
   */
  displaySurface: string | null
  /** Viewer only: the route to the host dropped and is being rebuilt. */
  reconnecting: boolean
  elapsed: number
  chatOpen: boolean
  chatUnread: number
  chatMsgs: ScreenChatLine[]
  /** Ref for the session's <video>; re-attaches its stream on every mount. */
  attachVideo: (el: HTMLVideoElement | null) => void
  open: (code: string) => void
  /** The session view is going away. A host carries on; a viewer stops. */
  leavePage: () => void
  stopSharing: () => void
  leaveViewer: () => void
  togglePause: () => void
  togglePreview: () => void
  toggleViewerMuted: () => void
  setApproval: (on: boolean) => void
  admit: (uuid: string, allow: boolean) => void
  setChatOpen: (open: boolean) => void
  sendChat: (text: string, toUuid: string) => void
}

const ScreenShareContext = createContext<ScreenShareContextValue>({
  code: '',
  session: undefined,
  loadError: null,
  isHost: false,
  phase: 'idle',
  errorMsg: '',
  viewers: [],
  knocks: [],
  approvalOn: null,
  paused: false,
  showPreview: false,
  viewerMuted: true,
  displaySurface: null,
  reconnecting: false,
  elapsed: 0,
  chatOpen: false,
  chatUnread: 0,
  chatMsgs: [],
  attachVideo: () => {},
  open: () => {},
  leavePage: () => {},
  stopSharing: () => {},
  leaveViewer: () => {},
  togglePause: () => {},
  togglePreview: () => {},
  toggleViewerMuted: () => {},
  setApproval: () => {},
  admit: () => {},
  setChatOpen: () => {},
  sendChat: () => {},
})

export const useScreenShare = () => useContext(ScreenShareContext)

/** Where the session draws itself full size. */
const SESSION_ROUTE = /^\/screen\/session\//

/**
 * How many ICE restarts a viewer tries before it simply waits for the browser
 * to call the route dead. Two, spaced out: a blip heals in seconds, and a
 * host who has closed the lid is not coming back however often we ask.
 */
const RESTART_LIMIT = 2

/**
 * Owns a screen session, so that walking around the app cannot end one.
 *
 * The share used to belong to the session page: its cleanup tore the peer
 * connections down and left the meeting, so a host who opened Tasks to check
 * something stopped sharing without being told, and everyone watching was left
 * on a frozen frame. Calls solved this long ago by living in CallProvider,
 * outside the Outlet; this puts screen sharing on the same footing.
 *
 * The page is a view onto what is held here. Navigating away changes nothing
 * about the connection: the capture, the peers and the signalling all carry on,
 * and a small floating bar says so with the way back.
 */
export function ScreenShareProvider({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  const navigate = useNavigate()
  const { pathname } = useLocation()

  const [code, setCode] = useState('')
  const [phase, setPhase] = useState<ScreenPhase>('idle')
  const [errorMsg, setErrorMsg] = useState('')
  const [viewers, setViewers] = useState<ScreenViewer[]>([])
  const [knocks, setKnocks] = useState<ScreenViewer[]>([])
  const [approvalOn, setApprovalOn] = useState<boolean | null>(null)
  const [paused, setPaused] = useState(false)
  const [showPreview, setShowPreview] = useState(false)
  const [viewerMuted, setViewerMuted] = useState(true)
  const [displaySurface, setDisplaySurface] = useState<string | null>(null)
  const [reconnecting, setReconnecting] = useState(false)
  const [elapsed, setElapsed] = useState(0)
  const [chatOpen, setChatOpenState] = useState(false)
  const [chatUnread, setChatUnread] = useState(0)
  const [chatMsgs, setChatMsgs] = useState<ScreenChatLine[]>([])
  const chatOpenRef = useRef(false)
  chatOpenRef.current = chatOpen

  const pcsRef = useRef<Map<string, RTCPeerConnection>>(new Map())
  const pendingIceRef = useRef<Map<string, RTCIceCandidateInit[]>>(new Map())
  const displayStreamRef = useRef<MediaStream | null>(null)
  /* Remembered stream + callback ref, shared with meetings and calls: a
   re-mounted <video> used to come back blank because the stream was only
   ever assigned onto the element that existed at the time. And now that the
   page comes and goes underneath a live session, it re-mounts far more often
   than it used to. */
  const { show: showVideo, attach: attachVideo, videoRef } = useSelfView()
  const iceServersRef = useRef<RTCIceServer[] | null>(null)
  const joinedRef = useRef(false)
  /** Mirrors `code` for the callbacks that run after the page has gone. */
  const codeRef = useRef('')
  const restartTimerRef = useRef<number | null>(null)
  const restartsRef = useRef(0)

  const { data: session, error: loadError } = useQuery({
    queryKey: ['screen-session', code],
    queryFn: () => meetingsApi.show(code),
    enabled: !!code,
    retry: false,
  })
  const isHost = session?.is_host ?? false
  /*
   * Who this browser is on the signalling channel.
   *
   * Offers, answers and candidates are delivered to a private channel named
   * after a uuid, and a guest has no account to take one from. They do hold
   * the pass they were given at the door, which carries a uuid of its own that
   * the server broadcasts to exactly as it does a member's. Without this a guest
   * watching a screen subscribed to nothing: their offer went out and the
   * host's answer never arrived, leaving them on a spinner for ever.
   *
   * Read when the session opens rather than when the provider mounts: this
   * sits above the router and is already running long before anybody types a
   * password, so a pass read once at start-up would always be missing.
   */
  const selfUuid = useMemo(() => {
    if (user?.uuid) return user.uuid
    const pass = readGuestPass()
    return pass?.code === code ? pass.uuid : undefined
  }, [user?.uuid, code])
  const onSessionRoute = SESSION_ROUTE.test(pathname)
  const pathnameRef = useRef(pathname)
  pathnameRef.current = pathname

  const flushPendingIce = useCallback((peerUuid: string) => {
    const pc = pcsRef.current.get(peerUuid)
    const pending = pendingIceRef.current.get(peerUuid)
    if (!pc || !pc.remoteDescription || !pending?.length) return
    pendingIceRef.current.delete(peerUuid)
    for (const c of pending) pc.addIceCandidate(c).catch(() => undefined)
  }, [])

  const clearRestart = useCallback(() => {
    if (restartTimerRef.current) window.clearTimeout(restartTimerRef.current)
    restartTimerRef.current = null
  }, [])

  const teardown = useCallback(() => {
    clearRestart()
    restartsRef.current = 0
    pcsRef.current.forEach((pc) => pc.close())
    pcsRef.current.clear()
    pendingIceRef.current.clear()
    displayStreamRef.current?.getTracks().forEach((t) => t.stop())
    displayStreamRef.current = null
    setViewers([])
  }, [clearRestart])

  /** Stop everything and forget the session, back to a provider holding nothing. */
  const closeSession = useCallback(() => {
    teardown()
    joinedRef.current = false
    codeRef.current = ''
    setCode('')
    setPhase('idle')
    setErrorMsg('')
    setKnocks([])
    setApprovalOn(null)
    setPaused(false)
    setShowPreview(false)
    setViewerMuted(true)
    setDisplaySurface(null)
    setReconnecting(false)
    setElapsed(0)
    setChatMsgs([])
    setChatUnread(0)
    setChatOpenState(false)
  }, [teardown])

  /**
   * Back to the Screen list, but only from the session's own page.
   *
   * Read at the moment it is called, never captured: the browser's own "Stop
   * sharing" button carries a handler built when the capture started, and that
   * one would remember a page the host walked away from ten minutes ago and
   * send them back to a list they never asked for.
   */
  const leaveRoute = useCallback(() => {
    if (SESSION_ROUTE.test(pathnameRef.current)) navigate('/screen')
  }, [navigate])

  const stopSharing = useCallback(() => {
    const current = codeRef.current
    teardown()
    joinedRef.current = false
    if (current) meetingsApi.end(current).catch(() => undefined)
    leaveRoute()
    closeSession()
  }, [teardown, leaveRoute, closeSession])

  const leaveViewer = useCallback(() => {
    const current = codeRef.current
    teardown()
    joinedRef.current = false
    if (current) meetingsApi.leave(current).catch(() => undefined)
    leaveRoute()
    closeSession()
  }, [teardown, leaveRoute, closeSession])

  /**
   * The session page unmounting.
   *
   * A host keeps sharing: that is the whole point of this provider, and the
   * floating bar takes over saying so. A viewer is only ever watching while
   * the picture is on screen, so for them this stays what it always was, a
   * teardown and a leave.
   */
  const leavePage = useCallback(() => {
    if (displayStreamRef.current) return
    const current = codeRef.current
    if (joinedRef.current && current) meetingsApi.leave(current).catch(() => undefined)
    closeSession()
  }, [closeSession])

  const newPeer = useCallback(async (peerUuid: string) => {
    const existing = pcsRef.current.get(peerUuid)
    if (existing) return existing
    if (!iceServersRef.current) iceServersRef.current = (await calls.config()).iceServers
    /* The pool gathers candidates before there is an offer to put them in, so
       the first exchange carries a usable set rather than waiting on the
       trickle behind it. */
    const pc = new RTCPeerConnection({ iceServers: iceServersRef.current, iceCandidatePoolSize: 4 })
    pcsRef.current.set(peerUuid, pc)
    pc.onicecandidate = (e) => {
      if (e.candidate) meetingsApi.signal(code, 'ice', { candidate: e.candidate.toJSON() }, peerUuid).catch(() => undefined)
    }
    return pc
  }, [code])

  /** A viewer's offer to the host; `fresh` asks for new ICE on the same peer. */
  const offerToHost = useCallback(async (pc: RTCPeerConnection, hostUuid: string, fresh = false) => {
    const offer = await pc.createOffer(fresh ? { iceRestart: true } : undefined)
    await pc.setLocalDescription(offer)
    await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, hostUuid)
  }, [code])

  /**
   * What a viewer does when the route to the host wobbles.
   *
   * A dropped route used to be permanent and silent: the last frame stayed on
   * screen, nothing retried, and the only way to learn the show was over was to
   * give up and close the page. A blip is worth a couple of ICE restarts,
   * spaced out; a route the browser has given up on is worth saying so.
   */
  const watchViewerRoute = useCallback((hostUuid: string, pc: RTCPeerConnection, state: string) => {
    // Not ours any more: a teardown has already closed this one.
    if (pcsRef.current.get(hostUuid) !== pc) return

    if (state === 'connected' || state === 'completed') {
      clearRestart()
      restartsRef.current = 0
      setReconnecting(false)
      return
    }

    if (state === 'failed' || state === 'closed') {
      teardown()
      setReconnecting(false)
      setPhase('ended')
      return
    }

    if (state !== 'disconnected') return
    setReconnecting(true)

    const schedule = () => {
      if (restartTimerRef.current || restartsRef.current >= RESTART_LIMIT) return
      // The first wait is short because most blips are over by then; the
      // second is longer, so two attempts do not both land inside one outage.
      const wait = restartsRef.current === 0 ? 3000 : 8000
      restartTimerRef.current = window.setTimeout(() => {
        restartTimerRef.current = null
        const live = pcsRef.current.get(hostUuid)
        if (live !== pc) return
        if (pc.iceConnectionState !== 'disconnected' && pc.connectionState !== 'disconnected') return
        restartsRef.current += 1
        offerToHost(pc, hostUuid, true).catch((err) => console.warn('[screen] ice restart failed', err))
        schedule()
      }, wait)
    }
    schedule()
  }, [clearRestart, teardown, offerToHost])

  /**
   * Connect to the host, receive-only.
   *
   * Both ways in used this, hand-written twice: arriving while the host is
   * already inside, and the host turning up afterwards. One copy means the
   * reconnect watching below cannot be fitted to one of them and missed off
   * the other.
   */
  const watchHost = useCallback(async (hostUuid: string) => {
    const pc = await newPeer(hostUuid)
    pc.addTransceiver('video', { direction: 'recvonly' })
    pc.addTransceiver('audio', { direction: 'recvonly' })
    pc.ontrack = (e) => showVideo(e.streams[0])
    pc.onconnectionstatechange = () => {
      console.info('[screen] host connection:', pc.connectionState)
      watchViewerRoute(hostUuid, pc, pc.connectionState)
    }
    pc.oniceconnectionstatechange = () => watchViewerRoute(hostUuid, pc, pc.iceConnectionState)
    await offerToHost(pc, hostUuid)
  }, [newPeer, showVideo, watchViewerRoute, offerToHost])

  const startSession = useCallback(async () => {
    const runStart = async () => {
      try {
        if (!session) return
        if (session.is_host) {
          const display = await navigator.mediaDevices
            .getDisplayMedia({ video: true, audio: true })
            .catch(() => navigator.mediaDevices.getDisplayMedia({ video: true }))
          /* The session can be closed while the picker is still open, and a
             capture belonging to nothing would go on lighting up the browser's
             sharing indicator with no way to stop it. */
          if (codeRef.current !== code) {
            display.getTracks().forEach((t) => t.stop())
            return
          }
          displayStreamRef.current = display
          const videoTrack = display.getVideoTracks()[0]
          /*
           * What the host actually picked in the browser's own dialogue.
           *
           * Not decoration: a single tab is frozen for everyone watching the
           * moment the host looks at a different one, and nothing in our code
           * can prevent that. The page warns them, and it can only do so if it
           * knows. Narrowed here because the property is newer than some
           * TypeScript DOM libraries.
           */
          const settings = videoTrack.getSettings() as MediaTrackSettings & { displaySurface?: string }
          setDisplaySurface(settings.displaySurface ?? null)
          videoTrack.onended = () => stopSharing()
          const hostInfo = await meetingsApi.join(code)
          setPhase('live')
          if (!('waiting' in hostInfo)) setApprovalOn(hostInfo.requires_approval ?? null)
        } else {
          const info = await meetingsApi.join(code)
          if ('waiting' in info && info.waiting) {
            setPhase('waiting')
            return
          }
          const room = info as Exclude<typeof info, { waiting: true }>
          setPhase('live')
          setApprovalOn(room.requires_approval ?? null)
          // Viewers connect ONLY to the host, receive-only.
          const hostUuid = room.host.uuid
          const hostInside = (room.joined_peers ?? []).some((p) => p.uuid === hostUuid)
          if (hostInside) await watchHost(hostUuid)
        }
      } catch (err) {
        setPhase('error')
        // A host on a phone used to be shown the raw exception —
        // "getDisplayMedia is not a function" — which describes the code
        // rather than the situation.
        // A null here means they simply dismissed the picker, which is not a
        // fault and should not read like one.
        const why = shareFailureMessage(err)
        setErrorMsg(
          why === null && session?.is_host
            ? 'You did not pick a screen to share. Press Retry to choose one.'
            : why ?? (err instanceof Error ? err.message : 'Could not start the session.'),
        )
      }
    }
    await runStart()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, code, watchHost])

  /**
   * Point the provider at a session. Called by the page on mount, so coming
   * back to a share this provider is already running picks it up where it was
   * rather than starting it again.
   */
  const open = useCallback((next: string) => {
    if (!next || codeRef.current === next) return
    // A different session while one is running: the old one is left behind,
    // which is what the page's own remount used to do.
    if (codeRef.current) leavePage()
    codeRef.current = next
    joinedRef.current = false
    setCode(next)
    setPhase('starting')
  }, [leavePage])

  /*
   * The ICE servers, asked for the moment a session opens.
   *
   * They used to be fetched inside the first newPeer, so the first offer of
   * the session waited on a round trip to the API before it could even be
   * built. Here it overlaps with the picker the host is looking at and with
   * the join, and by the time a peer is wanted the answer is in hand.
   */
  useEffect(() => {
    if (!code || iceServersRef.current) return
    calls.config()
      .then((cfg) => { iceServersRef.current = cfg.iceServers })
      .catch(() => { /* newPeer asks again; this was only the head start */ })
  }, [code])

  // Kick off once loaded. Meeting codes belong to the Meetings module.
  useEffect(() => {
    if (!session || joinedRef.current) return
    if (!session.is_screen) {
      navigate(livePath('meeting', code), { replace: true })
      return
    }
    joinedRef.current = true
    startSession()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session])

  // Signalling: host answers viewer offers with the display stream attached.
  useEffect(() => {
    if (!selfUuid || !session) return
    const echo = getEcho()
    if (!echo) return
    const channel = echo.private(`user.${selfUuid}`)

    const handler = async (signal: MeetingSignalPayload) => {
      if (signal.meeting_code !== code) return
      switch (signal.signal) {
        case 'join': {
          setViewers((v) => (v.some((x) => x.uuid === signal.from_uuid) ? v : [...v, { uuid: signal.from_uuid, name: signal.from_name ?? 'Viewer' }]))
          // Viewer arrived before the host: offer as soon as the host shows up.
          if (!session.is_host && signal.from_uuid === session.host.uuid && !pcsRef.current.has(signal.from_uuid)) {
            try {
              await watchHost(signal.from_uuid)
            } catch (err) {
              console.warn('[screen] offering to late host failed', err)
            }
          }
          break
        }
        case 'leave': {
          setViewers((v) => v.filter((x) => x.uuid !== signal.from_uuid))
          pcsRef.current.get(signal.from_uuid)?.close()
          pcsRef.current.delete(signal.from_uuid)
          // Viewer: the host leaving means the show is over.
          if (!session.is_host && signal.from_uuid === session.host.uuid) {
            teardown()
            setPhase('ended')
          }
          break
        }
        case 'end':
          teardown()
          setPhase('ended')
          break
        case 'knock':
          setKnocks((k) => (k.some((x) => x.uuid === signal.from_uuid) ? k : [...k, { uuid: signal.from_uuid, name: signal.from_name ?? 'Someone' }]))
          break
        case 'admitted':
          startSession()
          break
        case 'denied':
          teardown()
          setPhase('denied')
          break
        case 'chat':
          setChatMsgs((m) => [...m, {
            name: signal.from_name ?? 'Someone',
            text: signal.payload.message as string,
            priv: !!signal.payload.private,
            me: false,
          }])
          if (!chatOpenRef.current) setChatUnread((n) => n + 1)
          break
        case 'offer': {
          // Only the HOST receives offers (from viewers).
          if (!session.is_host || !displayStreamRef.current) return
          try {
            const pc = await newPeer(signal.from_uuid)
            // A second offer on a peer we already have is that viewer
            // restarting ICE, and the screen is already on it: adding the
            // tracks again throws and the answer never goes out.
            if (!pc.getSenders().length) {
              displayStreamRef.current.getTracks().forEach((t) => pc.addTrack(t, displayStreamRef.current!))
            }
            await pc.setRemoteDescription({ type: 'offer', sdp: normalizeSdp(signal.payload.sdp as string) })
            flushPendingIce(signal.from_uuid)
            const answer = await pc.createAnswer()
            await pc.setLocalDescription(answer)
            await meetingsApi.signal(code, 'answer', { sdp: answer.sdp, type: answer.type }, signal.from_uuid)
            setViewers((v) => (v.some((x) => x.uuid === signal.from_uuid) ? v : [...v, { uuid: signal.from_uuid, name: signal.from_name ?? 'Viewer' }]))
          } catch (err) {
            console.warn('[screen] answering viewer failed', err)
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
            console.warn('[screen] answer failed', err)
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
      /* This handler, not the event: a meeting kept alive by MeetingHost
         listens to the same name on the same channel, and a bare
         stopListening would take its ear off too. */
      channel.stopListening('.meeting.signal', handler)
    }
  }, [selfUuid, session, code, newPeer, teardown, flushPendingIce, startSession, watchHost])

  useEffect(() => {
    if (phase !== 'live') return
    const t = setInterval(() => setElapsed((s) => s + 1), 1000)
    return () => clearInterval(t)
  }, [phase])

  /*
   * The shell itself going away: signing out, or crossing between the personal
   * app and the CRM, each of which has a provider of its own.
   *
   * A share cannot outlive the thing holding it, so rather than leaving a
   * capture nobody owns and a viewer watching a frame that will never move
   * again, it ends here the way it always did on the page.
   */
  useEffect(() => {
    return () => {
      if (joinedRef.current && codeRef.current) meetingsApi.leave(codeRef.current).catch(() => undefined)
      teardown()
    }
  }, [teardown])

  const togglePreview = useCallback(() => {
    const next = !showPreview
    setShowPreview(next)
    showVideo(next ? displayStreamRef.current : null)
  }, [showPreview, showVideo])

  const togglePause = useCallback(() => {
    const next = !paused
    displayStreamRef.current?.getVideoTracks().forEach((t) => (t.enabled = !next))
    setPaused(next)
  }, [paused])

  const toggleViewerMuted = useCallback(() => {
    const next = !viewerMuted
    setViewerMuted(next)
    if (videoRef.current) {
      videoRef.current.muted = next
      videoRef.current.play().catch(() => undefined)
    }
  }, [viewerMuted, videoRef])

  const setApproval = useCallback((on: boolean) => {
    meetingsApi.setApproval(code, on).then(() => setApprovalOn(on)).catch(() => undefined)
  }, [code])

  const admit = useCallback((uuid: string, allow: boolean) => {
    meetingsApi.admit(code, uuid, allow).catch(() => undefined)
    setKnocks((ks) => ks.filter((x) => x.uuid !== uuid))
  }, [code])

  const setChatOpen = useCallback((open: boolean) => {
    setChatOpenState(open)
    if (open) setChatUnread(0)
  }, [])

  const sendChat = useCallback((text: string, toUuid: string) => {
    setChatMsgs((m) => [...m, { name: 'You', text, priv: !!toUuid, me: true }])
    meetingsApi.chat(code, text, toUuid || null).catch(() => undefined)
  }, [code])

  return (
    <ScreenShareContext.Provider
      value={{
        code,
        session,
        loadError,
        isHost,
        phase,
        errorMsg,
        viewers,
        knocks,
        approvalOn,
        paused,
        showPreview,
        viewerMuted,
        displaySurface,
        reconnecting,
        elapsed,
        chatOpen,
        chatUnread,
        chatMsgs,
        attachVideo,
        open,
        leavePage,
        stopSharing,
        leaveViewer,
        togglePause,
        togglePreview,
        toggleViewerMuted,
        setApproval,
        admit,
        setChatOpen,
        sendChat,
      }}
    >
      {children}

      {/*
        * Proof the share is still running, from wherever in the app the host
        * has wandered to.
        *
        * Bottom centre and below the call window's z-index, because the two can
        * easily be up at once: a call docks bottom right and fills the screen
        * on a phone, and this must never be what is covering the End button.
        */}
      {isHost && phase === 'live' && !onSessionRoute && (
        <div className="fixed bottom-20 left-1/2 z-[55] flex max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 shadow-2xl dark:border-slate-700 dark:bg-slate-900 sm:bottom-4">
          <MonitorUp className="size-4 shrink-0 text-emerald-500" />
          <span className="truncate text-xs font-medium">
            Sharing your screen · {viewers.length} watching
          </span>
          <Button size="sm" variant="secondary" onClick={() => navigate(livePath('screen', code))}>
            Show
          </Button>
          <Button size="sm" variant="danger" onClick={stopSharing}>
            <MonitorOff className="size-3.5" /> Stop
          </Button>
        </div>
      )}
    </ScreenShareContext.Provider>
  )
}
