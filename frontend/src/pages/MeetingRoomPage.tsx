import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Copy, Mic, MicOff, MonitorUp, PhoneOff, Users, Video, VideoOff,
} from 'lucide-react'
import { clsx } from 'clsx'
import { calls, meetings as meetingsApi } from '../api/endpoints'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { Button, Card } from '../components/ui'
import { meetingLink } from './MeetingsPage'
import type { MeetingSignalPayload } from '../types'

interface Peer {
  uuid: string
  name: string
  stream: MediaStream | null
}

function PeerTile({ peer, video }: { peer: Peer; video: boolean }) {
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
        {peer.name}
      </div>
    )
  }
  return (
    <div className="relative min-h-40 overflow-hidden rounded-lg bg-slate-900">
      <video ref={attach} autoPlay playsInline className="h-full w-full object-cover" />
      <span className="absolute bottom-1.5 left-1.5 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white">
        {peer.name}
      </span>
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
  const [phase, setPhase] = useState<'joining' | 'in' | 'ended' | 'error'>('joining')
  const [errorMsg, setErrorMsg] = useState('')
  const [muted, setMuted] = useState(false)
  const [cameraOff, setCameraOff] = useState(false)
  const [sharing, setSharing] = useState(false)
  const [elapsed, setElapsed] = useState(0)

  const pcsRef = useRef<Map<string, RTCPeerConnection>>(new Map())
  const pendingIceRef = useRef<Map<string, RTCIceCandidateInit[]>>(new Map())
  const localStreamRef = useRef<MediaStream | null>(null)
  const cameraTrackRef = useRef<MediaStreamTrack | null>(null)
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

  const teardown = useCallback(() => {
    pcsRef.current.forEach((pc) => pc.close())
    pcsRef.current.clear()
    pendingIceRef.current.clear()
    localStreamRef.current?.getTracks().forEach((t) => t.stop())
    localStreamRef.current = null
    setPeers([])
  }, [])

  // Join once the meeting is loaded.
  useEffect(() => {
    if (!meeting || joinedRef.current) return
    joinedRef.current = true
    ;(async () => {
      try {
        await ensureLocalStream()
        const info = await meetingsApi.join(code)
        setPhase('in')
        for (const peer of info.joined_peers ?? []) {
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
    })()
  }, [meeting, code, createPeer, ensureLocalStream])

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
  }, [user?.uuid, code, createPeer, removePeer, teardown, flushPendingIce])

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

  const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

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
          </p>
        </div>
        <Button
          size="sm"
          variant="secondary"
          onClick={() =>
            navigator.clipboard.writeText(meetingLink(code)).then(
              () => alert('Invite link copied.'),
              () => prompt('Copy this link:', meetingLink(code)),
            )
          }
        >
          <Copy className="size-3.5" /> Copy invite link
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
            <span className="absolute bottom-1.5 left-1.5 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white">
              You{sharing && ' (sharing screen)'}
            </span>
          </div>
        )}
        {!peers.length && phase === 'in' && (
          <Card className="flex items-center justify-center text-sm text-slate-400">
            Waiting for others — share the invite link.
          </Card>
        )}
        {peers.map((p) => (
          <PeerTile key={p.uuid} peer={p} video={isVideo} />
        ))}
        {!isVideo && (
          <div className="flex items-center gap-2 rounded-lg bg-slate-800 px-3 py-2 text-sm text-white">
            <span className="flex size-7 items-center justify-center rounded-full bg-emerald-600 text-xs font-semibold">
              {user?.name?.charAt(0) ?? 'Y'}
            </span>
            You {muted && '(muted)'}
          </div>
        )}
      </div>

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
