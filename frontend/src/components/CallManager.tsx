import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { Mic, MicOff, Phone, PhoneOff, Video, VideoOff } from 'lucide-react'
import { calls } from '../api/endpoints'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { Button } from './ui'
import type { CallSignalPayload } from '../types'

interface ActiveCall {
  uuid: string
  type: 'audio' | 'video'
  direction: 'outgoing' | 'incoming'
  peerName: string
  status: 'ringing' | 'connecting' | 'ongoing'
  startedAt?: number
}

interface CallContextValue {
  startCall: (conversationUuid: string, type: 'audio' | 'video', peerName: string) => Promise<void>
  activeCall: ActiveCall | null
}

const CallContext = createContext<CallContextValue>({ startCall: async () => {}, activeCall: null })

export const useCalls = () => useContext(CallContext)

export function CallProvider({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user)
  const [activeCall, setActiveCall] = useState<ActiveCall | null>(null)
  const [incoming, setIncoming] = useState<CallSignalPayload | null>(null)
  const [muted, setMuted] = useState(false)
  const [cameraOff, setCameraOff] = useState(false)
  const [elapsed, setElapsed] = useState(0)

  const pcRef = useRef<RTCPeerConnection | null>(null)
  const localStreamRef = useRef<MediaStream | null>(null)
  const localVideoRef = useRef<HTMLVideoElement>(null)
  const remoteVideoRef = useRef<HTMLVideoElement>(null)
  const remoteAudioRef = useRef<HTMLAudioElement>(null)
  const callRef = useRef<ActiveCall | null>(null)
  callRef.current = activeCall

  const cleanup = useCallback(() => {
    pcRef.current?.close()
    pcRef.current = null
    localStreamRef.current?.getTracks().forEach((t) => t.stop())
    localStreamRef.current = null
    setActiveCall(null)
    setIncoming(null)
    setMuted(false)
    setCameraOff(false)
    setElapsed(0)
  }, [])

  const setupPeer = useCallback(async (callUuid: string, type: 'audio' | 'video') => {
    const { iceServers } = await calls.config()
    const pc = new RTCPeerConnection({ iceServers })
    pcRef.current = pc

    const stream = await navigator.mediaDevices.getUserMedia({
      audio: true,
      video: type === 'video',
    })
    localStreamRef.current = stream
    stream.getTracks().forEach((track) => pc.addTrack(track, stream))
    if (localVideoRef.current) localVideoRef.current.srcObject = stream

    pc.ontrack = (event) => {
      const [remote] = event.streams
      if (remoteVideoRef.current) remoteVideoRef.current.srcObject = remote
      if (remoteAudioRef.current) remoteAudioRef.current.srcObject = remote
    }

    pc.onicecandidate = (event) => {
      if (event.candidate) {
        calls.signal(callUuid, 'ice', { candidate: event.candidate.toJSON() }).catch(() => undefined)
      }
    }

    return pc
  }, [])

  const startCall = useCallback(
    async (conversationUuid: string, type: 'audio' | 'video', peerName: string) => {
      if (callRef.current) return
      try {
        const call = await calls.initiate(conversationUuid, type)
        setActiveCall({ uuid: call.uuid, type, direction: 'outgoing', peerName, status: 'ringing' })
        await setupPeer(call.uuid, type)
      } catch (err) {
        cleanup()
        alert(err instanceof Error && 'response' in err
          ? ((err as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'Could not start the call.')
          : 'Could not start the call.')
      }
    },
    [setupPeer, cleanup],
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
      status: 'connecting',
    })
    try {
      await setupPeer(signal.call_uuid, signal.call_type)
      await calls.respond(signal.call_uuid, 'accept')
      // Callee creates the offer once accepted (caller answers).
      const pc = pcRef.current!
      const offer = await pc.createOffer()
      await pc.setLocalDescription(offer)
      await calls.signal(signal.call_uuid, 'offer', { sdp: offer.sdp, type: offer.type })
    } catch {
      cleanup()
    }
  }, [incoming, setupPeer, cleanup])

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
      const pc = pcRef.current

      switch (signal.signal) {
        case 'ring':
          if (!callRef.current) setIncoming(signal)
          break
        case 'accept':
          // Peer accepted our outgoing call; they will send an offer.
          setActiveCall((c) => (c ? { ...c, status: 'connecting' } : c))
          break
        case 'offer': {
          if (!pc) return
          await pc.setRemoteDescription({ type: 'offer', sdp: signal.payload.sdp as string })
          const answer = await pc.createAnswer()
          await pc.setLocalDescription(answer)
          await calls.signal(signal.call_uuid, 'answer', { sdp: answer.sdp, type: answer.type })
          setActiveCall((c) => (c ? { ...c, status: 'ongoing', startedAt: Date.now() } : c))
          break
        }
        case 'answer':
          if (!pc) return
          await pc.setRemoteDescription({ type: 'answer', sdp: signal.payload.sdp as string })
          setActiveCall((c) => (c ? { ...c, status: 'ongoing', startedAt: Date.now() } : c))
          break
        case 'ice':
          if (pc && signal.payload.candidate) {
            pc.addIceCandidate(signal.payload.candidate as RTCIceCandidateInit).catch(() => undefined)
          }
          break
        case 'decline':
        case 'end':
          cleanup()
          break
      }
    })

    return () => {
      echo.leave(`user.${user.uuid}`)
    }
  }, [user?.uuid, cleanup])

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
        <div className="fixed bottom-4 right-4 z-[60] w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
          <div className="relative bg-slate-950">
            {activeCall.type === 'video' ? (
              <>
                <video ref={remoteVideoRef} autoPlay playsInline className="h-44 w-full object-cover" />
                <video
                  ref={localVideoRef}
                  autoPlay
                  playsInline
                  muted
                  className="absolute bottom-2 right-2 h-16 w-24 rounded-lg border border-slate-700 object-cover"
                />
              </>
            ) : (
              <div className="flex h-24 items-center justify-center">
                <audio ref={remoteAudioRef} autoPlay />
                <video ref={localVideoRef} className="hidden" muted />
                <video ref={remoteVideoRef} className="hidden" />
                <Phone className="size-8 text-slate-500" />
              </div>
            )}
          </div>
          <div className="p-3">
            <p className="text-sm font-semibold">{activeCall.peerName}</p>
            <p className="text-xs text-slate-400">
              {activeCall.status === 'ringing' && 'Ringing…'}
              {activeCall.status === 'connecting' && 'Connecting…'}
              {activeCall.status === 'ongoing' && fmt(elapsed)}
            </p>
            <div className="mt-2 flex gap-2">
              <Button size="sm" variant="secondary" onClick={toggleMute} title={muted ? 'Unmute' : 'Mute'}>
                {muted ? <MicOff className="size-3.5" /> : <Mic className="size-3.5" />}
              </Button>
              {activeCall.type === 'video' && (
                <Button size="sm" variant="secondary" onClick={toggleCamera} title="Toggle camera">
                  {cameraOff ? <VideoOff className="size-3.5" /> : <Video className="size-3.5" />}
                </Button>
              )}
              <Button size="sm" variant="danger" onClick={hangUp} className="ml-auto">
                <PhoneOff className="size-3.5" /> End
              </Button>
            </div>
          </div>
        </div>
      )}
    </CallContext.Provider>
  )
}
