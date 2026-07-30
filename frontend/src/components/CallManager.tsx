import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { Mic, MicOff, Phone, PhoneOff, Users, Video, VideoOff } from 'lucide-react'
import { calls } from '../api/endpoints'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { Button } from './ui'
import type { CallSignalPayload } from '../types'
import { startRingtone } from '../lib/alerts'

interface ActiveCall {
  uuid: string
  type: 'audio' | 'video'
  direction: 'outgoing' | 'incoming'
  peerName: string
  isGroup: boolean
  status: 'ringing' | 'connecting' | 'ongoing'
  startedAt?: number
}

interface RemotePeer {
  uuid: string
  name: string
  stream: MediaStream | null
}

interface CallContextValue {
  startCall: (conversationUuid: string, type: 'audio' | 'video', peerName: string) => Promise<void>
  activeCall: ActiveCall | null
}

const CallContext = createContext<CallContextValue>({ startCall: async () => {}, activeCall: null })

export const useCalls = () => useContext(CallContext)

/** Attaches a MediaStream to a video/audio element via ref callback. */
function RemoteTile({ peer, video }: { peer: RemotePeer; video: boolean }) {
  const attach = (el: HTMLVideoElement | HTMLAudioElement | null) => {
    if (el && el.srcObject !== peer.stream) el.srcObject = peer.stream
  }
  if (!video) {
    return <audio ref={attach} autoPlay />
  }
  return (
    <div className="relative overflow-hidden rounded-lg bg-slate-900">
      <video ref={attach} autoPlay playsInline className="h-full w-full object-cover" />
      <span className="absolute bottom-1 left-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white">
        {peer.name}
      </span>
    </div>
  )
}

/**
 * Mesh calling: every participant keeps a direct RTCPeerConnection to every
 * other participant. The rule that keeps signalling deterministic: whoever
 * JOINS the call sends the offers to everyone already in it.
 */
export function CallProvider({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  const [activeCall, setActiveCall] = useState<ActiveCall | null>(null)
  const [incoming, setIncoming] = useState<CallSignalPayload | null>(null)
  const [remotePeers, setRemotePeers] = useState<RemotePeer[]>([])
  const [muted, setMuted] = useState(false)
  const [cameraOff, setCameraOff] = useState(false)
  const [elapsed, setElapsed] = useState(0)

  const peersRef = useRef<Map<string, RTCPeerConnection>>(new Map())
  const iceServersRef = useRef<RTCIceServer[] | null>(null)
  const localStreamRef = useRef<MediaStream | null>(null)
  const localVideoRef = useRef<HTMLVideoElement>(null)
  const callRef = useRef<ActiveCall | null>(null)
  callRef.current = activeCall

  const cleanup = useCallback(() => {
    peersRef.current.forEach((pc) => pc.close())
    peersRef.current.clear()
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
    const stream = await navigator.mediaDevices.getUserMedia({
      audio: true,
      video: type === 'video',
    })
    localStreamRef.current = stream
    if (localVideoRef.current) localVideoRef.current.srcObject = stream
    return stream
  }, [])

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

      setRemotePeers((peers) =>
        peers.some((p) => p.uuid === peerUuid) ? peers : [...peers, { uuid: peerUuid, name: peerName, stream: null }],
      )

      pc.ontrack = (event) => {
        const [remote] = event.streams
        setRemotePeers((peers) => peers.map((p) => (p.uuid === peerUuid ? { ...p, stream: remote } : p)))
      }

      pc.onicecandidate = (event) => {
        if (event.candidate) {
          calls.signal(callUuid, 'ice', { candidate: event.candidate.toJSON() }, peerUuid).catch(() => undefined)
        }
      }

      return pc
    },
    [ensureLocalStream],
  )

  const removePeer = useCallback((peerUuid: string) => {
    peersRef.current.get(peerUuid)?.close()
    peersRef.current.delete(peerUuid)
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

  const declineIncoming = useCallback(async () => {
    if (!incoming) return
    const uuid = incoming.call_uuid
    setIncoming(null)
    calls.respond(uuid, 'decline').catch(() => undefined)
  }, [incoming])

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
          const pc = await createPeer(
            signal.call_uuid,
            signal.from_uuid,
            signal.from_name ?? 'Participant',
            call.type,
          )
          await pc.setRemoteDescription({ type: 'offer', sdp: signal.payload.sdp as string })
          const answer = await pc.createAnswer()
          await pc.setLocalDescription(answer)
          await calls.signal(signal.call_uuid, 'answer', { sdp: answer.sdp, type: answer.type }, signal.from_uuid)
          setActiveCall((c) => (c ? { ...c, status: 'ongoing', startedAt: c.startedAt ?? Date.now() } : c))
          break
        }
        case 'answer': {
          const pc = peersRef.current.get(signal.from_uuid)
          if (!pc) return
          await pc.setRemoteDescription({ type: 'answer', sdp: signal.payload.sdp as string })
          setActiveCall((c) => (c ? { ...c, status: 'ongoing', startedAt: c.startedAt ?? Date.now() } : c))
          break
        }
        case 'ice': {
          const pc = peersRef.current.get(signal.from_uuid)
          if (pc && signal.payload.candidate) {
            pc.addIceCandidate(signal.payload.candidate as RTCIceCandidateInit).catch(() => undefined)
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
  }, [user?.uuid, cleanup, createPeer, removePeer])

  // Ringtone for incoming calls; ring-back while our outgoing call rings.
  useEffect(() => {
    if (incoming) return startRingtone('incoming')
  }, [incoming])
  useEffect(() => {
    if (activeCall?.status === 'ringing' && activeCall.direction === 'outgoing') {
      return startRingtone('outgoing')
    }
  }, [activeCall?.status, activeCall?.direction])

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

  const toggleMute = () => {
    localStreamRef.current?.getAudioTracks().forEach((t) => (t.enabled = muted))
    setMuted(!muted)
  }

  const toggleCamera = () => {
    localStreamRef.current?.getVideoTracks().forEach((t) => (t.enabled = cameraOff))
    setCameraOff(!cameraOff)
  }

  const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

  const isVideo = activeCall?.type === 'video'
  const tiles = remotePeers.length
  const gridCols = tiles <= 1 ? 'grid-cols-1' : tiles <= 4 ? 'grid-cols-2' : 'grid-cols-3'
  const wide = activeCall?.isGroup && tiles > 1

  return (
    <CallContext.Provider value={{ startCall, activeCall }}>
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

      {/* Active call window */}
      {activeCall && (
        <div
          className={
            'fixed bottom-4 right-4 z-[60] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900 ' +
            (wide ? 'w-[34rem] max-w-[calc(100vw-2rem)]' : 'w-80')
          }
        >
          <div className="relative bg-slate-950 p-1">
            {isVideo ? (
              <>
                <div className={`grid ${gridCols} h-56 gap-1`}>
                  {remotePeers.length === 0 ? (
                    <div className="flex items-center justify-center text-xs text-slate-500">Waiting for others…</div>
                  ) : (
                    remotePeers.map((p) => <RemoteTile key={p.uuid} peer={p} video />)
                  )}
                </div>
                <video
                  ref={localVideoRef}
                  autoPlay
                  playsInline
                  muted
                  className="absolute bottom-2 right-2 h-16 w-24 rounded-lg border border-slate-700 object-cover"
                />
              </>
            ) : (
              <div className="flex h-24 items-center justify-center gap-2">
                {remotePeers.map((p) => (
                  <RemoteTile key={p.uuid} peer={p} video={false} />
                ))}
                <video ref={localVideoRef} className="hidden" muted />
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
          <div className="p-3">
            <p className="text-sm font-semibold">{activeCall.peerName}</p>
            <p className="text-xs text-slate-400">
              {activeCall.status === 'ringing' && 'Ringing…'}
              {activeCall.status === 'connecting' && 'Connecting…'}
              {activeCall.status === 'ongoing' && fmt(elapsed)}
              {activeCall.isGroup && activeCall.status === 'ongoing' && ` · ${tiles + 1} participants`}
            </p>
            <div className="mt-2 flex gap-2">
              <Button size="sm" variant="secondary" onClick={toggleMute} title={muted ? 'Unmute' : 'Mute'}>
                {muted ? <MicOff className="size-3.5" /> : <Mic className="size-3.5" />}
              </Button>
              {isVideo && (
                <Button size="sm" variant="secondary" onClick={toggleCamera} title="Toggle camera">
                  {cameraOff ? <VideoOff className="size-3.5" /> : <Video className="size-3.5" />}
                </Button>
              )}
              <Button size="sm" variant="danger" onClick={hangUp} className="ml-auto">
                <PhoneOff className="size-3.5" /> {activeCall.isGroup ? 'Leave' : 'End'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </CallContext.Provider>
  )
}
