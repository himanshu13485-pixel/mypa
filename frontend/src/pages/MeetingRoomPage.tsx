import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Copy, Hand, Lock, LockOpen, MessageSquare, Mic, MicOff, MonitorUp, PhoneOff, SmilePlus, Users, Video, VideoOff,
} from 'lucide-react'
import { clsx } from 'clsx'
import { calls, meetings as meetingsApi } from '../api/endpoints'
import { errorMessage } from '../api/client'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { Button, Card } from '../components/ui'
import { meetingLink } from './MeetingsPage'
import type { MeetingSignalPayload } from '../types'

const REACTIONS: Record<string, string> = {
  thumbsup: '\u{1F44D}', clap: '\u{1F44F}', heart: '\u{2764}\u{FE0F}', laugh: '\u{1F602}',
  wow: '\u{1F62E}', party: '\u{1F389}', hand: '\u{270B}',
}

interface Peer {
  uuid: string
  name: string
  stream: MediaStream | null
}

function PeerTile({ peer, video, burst, hand, isHost }: { peer: Peer; video: boolean; burst?: string; hand?: boolean; isHost?: boolean }) {
  const attach = (el: HTMLVideoElement | HTMLAudioElement | null) => {
    if (el && el.srcObject !== peer.stream) {
      el.srcObject = peer.stream
      el.play().catch(() => undefined)
    }
  }
  if (!video) {
    return (
      <div className="flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-sm text-white">
        <audio ref={attach} autoPlay />
        <span className="flex size-7 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold">
          {peer.name.charAt(0)}
        </span>
        {peer.name}{isHost && <span className="text-[10px] text-amber-400"> (Host)</span>}
        {hand && <span className="text-base">{REACTIONS.hand}</span>}
        {burst && <span className="animate-bounce text-xl">{REACTIONS[burst]}</span>}
      </div>
    )
  }
  return (
    <div className="relative min-h-40 overflow-hidden rounded-lg bg-slate-900">
      <video ref={attach} autoPlay playsInline className="h-full w-full object-cover" />
      <span className="absolute bottom-1.5 left-1.5 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white">
        {peer.name}{isHost && ' (Host)'}{hand && ` ${REACTIONS.hand}`}
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
 */
export default function MeetingRoomPage() {
  const { code = '' } = useParams()
  const navigate = useNavigate()
  const user = useAuthStore((s) => s.user)

  const [peers, setPeers] = useState<Peer[]>([])
  const [phase, setPhase] = useState<'joining' | 'waiting' | 'denied' | 'in' | 'ended' | 'error'>('joining')
  const [errorMsg, setErrorMsg] = useState('')
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
  const [knocks, setKnocks] = useState<{ uuid: string; name: string }[]>([])
  const [approvalOn, setApprovalOn] = useState<boolean | null>(null)
  const [chatOpen, setChatOpen] = useState(false)
  const [chatUnread, setChatUnread] = useState(0)
  const [chatTo, setChatTo] = useState('') // '' = everyone
  const [chatDraft, setChatDraft] = useState('')
  const [chatMsgs, setChatMsgs] = useState<{ from: string; name: string; text: string; priv: boolean; me: boolean }[]>([])
  const chatOpenRef = useRef(false)
  chatOpenRef.current = chatOpen

  const pcsRef = useRef<Map<string, RTCPeerConnection>>(new Map())
  const pendingIceRef = useRef<Map<string, RTCIceCandidateInit[]>>(new Map())
  const localStreamRef = useRef<MediaStream | null>(null)
  const cameraTrackRef = useRef<MediaStreamTrack | null>(null)
  const displayTrackRef = useRef<MediaStreamTrack | null>(null)
  const localVideoRef = useRef<HTMLVideoElement>(null)
  const iceServersRef = useRef<RTCIceServer[] | null>(null)
  const joinedRef = useRef(false)

  const { data: meeting } = useQuery({
    queryKey: ['meeting', code],
    queryFn: () => meetingsApi.show(code),
    retry: false,
  })
  const isVideo = meeting?.type !== 'audio'

  const ensureLocalStream = useCallback(async () => {
    if (localStreamRef.current) return localStreamRef.current
    const stream = await navigator.mediaDevices.getUserMedia({
      audio: true,
      video: meeting?.type !== 'audio',
    })
    localStreamRef.current = stream
    cameraTrackRef.current = stream.getVideoTracks()[0] ?? null
    if (localVideoRef.current) localVideoRef.current.srcObject = stream
    return stream
  }, [meeting?.type])

  const flushPendingIce = useCallback((peerUuid: string) => {
    const pc = pcsRef.current.get(peerUuid)
    const pending = pendingIceRef.current.get(peerUuid)
    if (!pc || !pc.remoteDescription || !pending?.length) return
    pendingIceRef.current.delete(peerUuid)
    for (const c of pending) pc.addIceCandidate(c).catch(() => undefined)
  }, [])

  const createPeer = useCallback(
    async (peerUuid: string, peerName: string) => {
      const existing = pcsRef.current.get(peerUuid)
      if (existing) return existing

      if (!iceServersRef.current) iceServersRef.current = (await calls.config()).iceServers
      const pc = new RTCPeerConnection({ iceServers: iceServersRef.current })
      pcsRef.current.set(peerUuid, pc)

      const stream = await ensureLocalStream()
      stream.getTracks().forEach((t) => pc.addTrack(t, stream))

      setPeers((p) => (p.some((x) => x.uuid === peerUuid) ? p : [...p, { uuid: peerUuid, name: peerName, stream: null }]))

      pc.ontrack = (event) => {
        const [remote] = event.streams
        setPeers((p) => p.map((x) => (x.uuid === peerUuid ? { ...x, stream: remote } : x)))
      }
      pc.onicecandidate = (event) => {
        if (event.candidate) {
          meetingsApi.signal(code, 'ice', { candidate: event.candidate.toJSON() }, peerUuid).catch(() => undefined)
        }
      }
      return pc
    },
    [code, ensureLocalStream],
  )

  const removePeer = useCallback((peerUuid: string) => {
    pcsRef.current.get(peerUuid)?.close()
    pcsRef.current.delete(peerUuid)
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
    localStreamRef.current?.getTracks().forEach((t) => t.stop())
    localStreamRef.current = null
    displayTrackRef.current?.stop() // otherwise the browser keeps sharing the screen
    displayTrackRef.current = null
    setSharing(false)
    setPeers([])
  }, [])

  const joinRoom = useCallback(async () => {
    try {
      await ensureLocalStream()
      const info = await meetingsApi.join(code)
      if ('waiting' in info && info.waiting) {
        setPhase('waiting')
        return
      }
      const room = info as Exclude<typeof info, { waiting: true }>
      setPhase('in')
      setApprovalOn(room.requires_approval ?? null)
      for (const peer of room.joined_peers ?? []) {
        const pc = await createPeer(peer.uuid, peer.name)
        const offer = await pc.createOffer()
        await pc.setLocalDescription(offer)
        await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, peer.uuid)
      }
    } catch (err) {
      setPhase('error')
      setErrorMsg(err instanceof Error ? err.message : 'Could not join the meeting.')
      console.warn('[meeting] join failed', err)
    }
  }, [code, createPeer, ensureLocalStream])

  // Join once the meeting is loaded.
  useEffect(() => {
    if (!meeting || joinedRef.current) return
    joinedRef.current = true
    joinRoom()
  }, [meeting, joinRoom])

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
          break
        case 'rename':
          setPeers((p) => p.map((x) => (x.uuid === signal.from_uuid ? { ...x, name: (signal.payload.name as string) ?? x.name } : x)))
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
        case 'chat':
          setChatMsgs((m) => [...m, {
            from: signal.from_uuid,
            name: signal.from_name ?? 'Someone',
            text: signal.payload.message as string,
            priv: !!signal.payload.private,
            me: false,
          }])
          if (!chatOpenRef.current) setChatUnread((n) => n + 1)
          break
        case 'end':
          teardown()
          setPhase('ended')
          break
        case 'offer': {
          try {
            const pc = await createPeer(signal.from_uuid, signal.from_name ?? 'Participant')
            await pc.setRemoteDescription({ type: 'offer', sdp: signal.payload.sdp as string })
            flushPendingIce(signal.from_uuid)
            const answer = await pc.createAnswer()
            await pc.setLocalDescription(answer)
            await meetingsApi.signal(code, 'answer', { sdp: answer.sdp, type: answer.type }, signal.from_uuid)
          } catch (err) {
            console.warn('[meeting] offer handling failed', err)
          }
          break
        }
        case 'answer': {
          const pc = pcsRef.current.get(signal.from_uuid)
          if (!pc) return
          try {
            await pc.setRemoteDescription({ type: 'answer', sdp: signal.payload.sdp as string })
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
  }, [user?.uuid, code, createPeer, removePeer, teardown, flushPendingIce, showBurst, joinRoom])

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

  // Timer + leave-on-unmount.
  useEffect(() => {
    if (phase !== 'in') return
    const t = setInterval(() => setElapsed((s) => s + 1), 1000)
    return () => clearInterval(t)
  }, [phase])

  useEffect(() => {
    return () => {
      teardown()
      if (joinedRef.current) meetingsApi.leave(code).catch(() => undefined)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const toggleMute = () => {
    const next = !muted
    localStreamRef.current?.getAudioTracks().forEach((t) => (t.enabled = !next))
    setMuted(next)
  }

  const toggleCamera = () => {
    const next = !cameraOff
    localStreamRef.current?.getVideoTracks().forEach((t) => (t.enabled = !next))
    setCameraOff(next)
  }

  /** Screen share: swap the outgoing video track on every peer connection. */
  const toggleShare = async () => {
    if (sharing) {
      stopShare()
      return
    }
    try {
      const display = await navigator.mediaDevices.getDisplayMedia({ video: true })
      const track = display.getVideoTracks()[0]
      displayTrackRef.current = track
      track.onended = stopShare
      pcsRef.current.forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
        sender?.replaceTrack(track).catch(() => undefined)
      })
      if (localVideoRef.current) localVideoRef.current.srcObject = new MediaStream([track])
      setSharing(true)
    } catch {
      /* user cancelled the picker */
    }
  }

  const stopShare = () => {
    const camera = cameraTrackRef.current
    pcsRef.current.forEach((pc) => {
      const sender = pc.getSenders().find((s) => s.track?.kind === 'video')
      if (camera) sender?.replaceTrack(camera).catch(() => undefined)
    })
    displayTrackRef.current?.stop() // release the browser's "sharing your screen" bar
    displayTrackRef.current = null
    if (localVideoRef.current && localStreamRef.current) localVideoRef.current.srcObject = localStreamRef.current
    setSharing(false)
  }

  const leave = async () => {
    teardown()
    joinedRef.current = false
    await meetingsApi.leave(code).catch(() => undefined)
    navigate('/meetings')
  }

  const endForAll = async () => {
    if (!confirm('End this meeting for everyone?')) return
    teardown()
    joinedRef.current = false
    await meetingsApi.end(code).catch(() => undefined)
    navigate('/meetings')
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
    const target = peers.find((p) => p.uuid === chatTo)
    setChatMsgs((m) => [...m, { from: 'me', name: 'You', text, priv: !!chatTo, me: true }])
    meetingsApi.chat(code, text, chatTo || null).catch((err) => alert(errorMessage(err)))
    void target
  }

  const changeMyName = () => {
    const name = prompt('Your name for this meeting:', myName ?? user?.name ?? '')
    if (!name?.trim()) return
    meetingsApi.rename(code, name.trim())
      .then(() => setMyName(name.trim()))
      .catch((err) => alert(errorMessage(err)))
  }

  const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

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

  if (phase === 'denied') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">The host did not admit you</p>
        <Button className="mt-4" onClick={() => navigate('/meetings')}>Back to Meetings</Button>
      </Card>
    )
  }

  if (phase === 'error' || phase === 'ended') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">{phase === 'ended' ? 'Meeting ended' : 'Could not join'}</p>
        <p className="mt-1 text-xs text-slate-400">{phase === 'ended' ? 'The host ended this meeting.' : errorMsg}</p>
        <Button className="mt-4" onClick={() => navigate('/meetings')}>Back to Meetings</Button>
      </Card>
    )
  }

  return (
    <div className="flex h-full flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-base font-semibold">{meeting?.title ?? 'Meeting'}</h1>
          <p className="flex items-center gap-2 text-xs text-slate-400">
            <span className="font-mono">{code}</span>
            {phase === 'in' ? <span className="text-emerald-600">{fmt(elapsed)}</span> : 'Connecting…'}
            <span className="flex items-center gap-1"><Users className="size-3" /> {peers.length + 1}</span>
            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              Host: {meeting?.is_host ? 'you' : meeting?.host.name}
            </span>
            {meeting?.is_host && approvalOn !== null && (
              <button
                className="flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 hover:text-brand-600 dark:bg-slate-800 dark:text-slate-400"
                title={approvalOn ? 'Waiting room ON — click for open access' : 'Open access — click to require your approval'}
                onClick={() => {
                  const next = !approvalOn
                  meetingsApi.setApproval(code, next).then(() => setApprovalOn(next)).catch((err) => alert(errorMessage(err)))
                }}
              >
                {approvalOn ? <Lock className="size-3" /> : <LockOpen className="size-3" />}
                {approvalOn ? 'Approval required' : 'Open access'}
              </button>
            )}
          </p>
          {meeting?.is_host && knocks.length > 0 && (
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

      {/* Tiles */}
      <div
        className={clsx(
          'grid flex-1 content-start gap-2',
          isVideo
            ? peers.length <= 1 ? 'grid-cols-1 sm:grid-cols-2' : peers.length <= 3 ? 'grid-cols-2' : 'grid-cols-2 lg:grid-cols-3'
            : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
        )}
      >
        {isVideo && (
          <div className="relative min-h-40 overflow-hidden rounded-lg bg-slate-900">
            <video ref={localVideoRef} autoPlay playsInline muted className="h-full w-full object-cover" />
            {bursts.me && <span className="absolute right-2 top-2 animate-bounce text-4xl drop-shadow">{REACTIONS[bursts.me]}</span>}
            <button
              className="absolute bottom-1.5 left-1.5 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white hover:bg-black/80"
              title="Change your name for this meeting"
              onClick={changeMyName}
            >
              {myName ?? 'You'}{myHand && ` ${REACTIONS.hand}`}{sharing && ' (sharing screen)'} ✎
            </button>
          </div>
        )}
        {!peers.length && phase === 'in' && (
          <Card className="flex items-center justify-center text-sm text-slate-400">
            Waiting for others — share the invite link.
          </Card>
        )}
        {peers.map((p) => (
          <PeerTile
            key={p.uuid}
            peer={p}
            video={isVideo}
            burst={bursts[p.uuid]}
            hand={hands.has(p.uuid)}
            isHost={p.uuid === meeting?.host.uuid}
          />
        ))}
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
      </div>

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
                  <span className="ml-1.5">{m.text}</span>
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
            <Button size="sm" onClick={sendChat} disabled={!chatDraft.trim()}>Send</Button>
          </div>
        </div>
      )}

      {/* Controls */}
      <div className="flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
        <Button size="sm" variant="secondary" onClick={toggleMute} title={muted ? 'Unmute' : 'Mute'}>
          {muted ? <MicOff className="size-4" /> : <Mic className="size-4" />}
        </Button>
        {isVideo && (
          <>
            <Button size="sm" variant="secondary" onClick={toggleCamera} title="Toggle camera">
              {cameraOff ? <VideoOff className="size-4" /> : <Video className="size-4" />}
            </Button>
            <Button size="sm" variant={sharing ? 'primary' : 'secondary'} onClick={toggleShare} title={sharing ? 'Stop sharing' : 'Share screen'}>
              <MonitorUp className="size-4" />
            </Button>
          </>
        )}
        <div className="relative">
          <Button size="sm" variant="secondary" title="Send a reaction" onClick={() => setShowReactions((s) => !s)}>
            <SmilePlus className="size-4" />
          </Button>
          {showReactions && (
            <div className="absolute bottom-11 left-1/2 z-20 flex -translate-x-1/2 gap-1 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900">
              {Object.entries(REACTIONS).filter(([k]) => k !== 'hand').map(([key, emoji]) => (
                <button key={key} className="rounded-lg p-1 text-xl hover:bg-slate-100 dark:hover:bg-slate-800" onClick={() => sendReaction(key)}>
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
        <Button size="sm" variant="danger" onClick={leave}>
          <PhoneOff className="size-4" /> Leave
        </Button>
        {meeting?.is_host && (
          <Button size="sm" variant="danger" onClick={endForAll}>
            End for all
          </Button>
        )}
      </div>
    </div>
  )
}
