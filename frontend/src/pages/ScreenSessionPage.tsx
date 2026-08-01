import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Copy, Expand, MonitorOff, MonitorUp, Pause, Play, Users } from 'lucide-react'
import { calls, meetings as meetingsApi } from '../api/endpoints'
import { getEcho } from '../lib/echo'
import { useAuthStore } from '../stores/auth'
import { Button, Card, Spinner } from '../components/ui'
import { screenLink } from './ScreenPage'
import type { MeetingSignalPayload } from '../types'

/**
 * A screen session: the HOST captures their screen and answers offers from
 * viewers; VIEWERS connect receive-only to the host and just watch. One-way
 * fan-out on the same signalling engine as meetings.
 */
export default function ScreenSessionPage() {
  const { code = '' } = useParams()
  const navigate = useNavigate()
  const user = useAuthStore((s) => s.user)

  const [phase, setPhase] = useState<'starting' | 'live' | 'ended' | 'error'>('starting')
  const [errorMsg, setErrorMsg] = useState('')
  const [viewers, setViewers] = useState<{ uuid: string; name: string }[]>([])
  const [paused, setPaused] = useState(false)
  const [copied, setCopied] = useState(false)
  const [elapsed, setElapsed] = useState(0)

  const pcsRef = useRef<Map<string, RTCPeerConnection>>(new Map())
  const pendingIceRef = useRef<Map<string, RTCIceCandidateInit[]>>(new Map())
  const displayStreamRef = useRef<MediaStream | null>(null)
  const videoRef = useRef<HTMLVideoElement>(null)
  const iceServersRef = useRef<RTCIceServer[] | null>(null)
  const joinedRef = useRef(false)

  const { data: session, error: loadError } = useQuery({
    queryKey: ['screen-session', code],
    queryFn: () => meetingsApi.show(code),
    retry: false,
  })
  const isHost = session?.is_host ?? false

  const flushPendingIce = useCallback((peerUuid: string) => {
    const pc = pcsRef.current.get(peerUuid)
    const pending = pendingIceRef.current.get(peerUuid)
    if (!pc || !pc.remoteDescription || !pending?.length) return
    pendingIceRef.current.delete(peerUuid)
    for (const c of pending) pc.addIceCandidate(c).catch(() => undefined)
  }, [])

  const newPeer = useCallback(async (peerUuid: string) => {
    const existing = pcsRef.current.get(peerUuid)
    if (existing) return existing
    if (!iceServersRef.current) iceServersRef.current = (await calls.config()).iceServers
    const pc = new RTCPeerConnection({ iceServers: iceServersRef.current })
    pcsRef.current.set(peerUuid, pc)
    pc.onicecandidate = (e) => {
      if (e.candidate) meetingsApi.signal(code, 'ice', { candidate: e.candidate.toJSON() }, peerUuid).catch(() => undefined)
    }
    return pc
  }, [code])

  const teardown = useCallback(() => {
    pcsRef.current.forEach((pc) => pc.close())
    pcsRef.current.clear()
    pendingIceRef.current.clear()
    displayStreamRef.current?.getTracks().forEach((t) => t.stop())
    displayStreamRef.current = null
    setViewers([])
  }, [])

  // Start: host captures the screen then joins; viewer joins and offers to the host.
  useEffect(() => {
    if (!session || joinedRef.current) return
    joinedRef.current = true
    ;(async () => {
      try {
        if (session.is_host) {
          const display = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true })
          displayStreamRef.current = display
          if (videoRef.current) videoRef.current.srcObject = display
          display.getVideoTracks()[0].onended = () => stopSharing()
          await meetingsApi.join(code)
          setPhase('live')
        } else {
          const info = await meetingsApi.join(code)
          if ('waiting' in info && info.waiting) {
            setPhase('error')
            setErrorMsg('The sharer has a waiting room on — ask them to admit you or open access.')
            return
          }
          const room = info as Exclude<typeof info, { waiting: true }>
          setPhase('live')
          // Viewers connect ONLY to the host, receive-only.
          const hostUuid = room.host.uuid
          const hostInside = (room.joined_peers ?? []).some((p) => p.uuid === hostUuid)
          if (hostInside) {
            const pc = await newPeer(hostUuid)
            pc.addTransceiver('video', { direction: 'recvonly' })
            pc.addTransceiver('audio', { direction: 'recvonly' })
            pc.ontrack = (e) => {
              if (videoRef.current && videoRef.current.srcObject !== e.streams[0]) {
                videoRef.current.srcObject = e.streams[0]
                videoRef.current.play().catch(() => undefined)
              }
            }
            const offer = await pc.createOffer()
            await pc.setLocalDescription(offer)
            await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, hostUuid)
          }
        }
      } catch (err) {
        setPhase('error')
        setErrorMsg(err instanceof Error ? err.message : 'Could not start the session.')
      }
    })()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, code])

  // Signalling: host answers viewer offers with the display stream attached.
  useEffect(() => {
    if (!user?.uuid || !session) return
    const echo = getEcho()
    if (!echo) return
    const channel = echo.private(`user.${user.uuid}`)

    const handler = async (signal: MeetingSignalPayload) => {
      if (signal.meeting_code !== code) return
      switch (signal.signal) {
        case 'join': {
          setViewers((v) => (v.some((x) => x.uuid === signal.from_uuid) ? v : [...v, { uuid: signal.from_uuid, name: signal.from_name ?? 'Viewer' }]))
          // Viewer arrived before the host: offer as soon as the host shows up.
          if (!session.is_host && signal.from_uuid === session.host.uuid && !pcsRef.current.has(signal.from_uuid)) {
            try {
              const pc = await newPeer(signal.from_uuid)
              pc.addTransceiver('video', { direction: 'recvonly' })
              pc.addTransceiver('audio', { direction: 'recvonly' })
              pc.ontrack = (e) => {
                if (videoRef.current && videoRef.current.srcObject !== e.streams[0]) {
                  videoRef.current.srcObject = e.streams[0]
                  videoRef.current.play().catch(() => undefined)
                }
              }
              const offer = await pc.createOffer()
              await pc.setLocalDescription(offer)
              await meetingsApi.signal(code, 'offer', { sdp: offer.sdp, type: offer.type }, signal.from_uuid)
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
        case 'offer': {
          // Only the HOST receives offers (from viewers).
          if (!session.is_host || !displayStreamRef.current) return
          try {
            const pc = await newPeer(signal.from_uuid)
            displayStreamRef.current.getTracks().forEach((t) => pc.addTrack(t, displayStreamRef.current!))
            await pc.setRemoteDescription({ type: 'offer', sdp: signal.payload.sdp as string })
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
            await pc.setRemoteDescription({ type: 'answer', sdp: signal.payload.sdp as string })
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
      channel.stopListening('.meeting.signal')
    }
  }, [user?.uuid, session, code, newPeer, teardown, flushPendingIce])

  useEffect(() => {
    if (phase !== 'live') return
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

  const togglePause = () => {
    const next = !paused
    displayStreamRef.current?.getVideoTracks().forEach((t) => (t.enabled = !next))
    setPaused(next)
  }

  const stopSharing = async () => {
    teardown()
    joinedRef.current = false
    await meetingsApi.end(code).catch(() => undefined)
    navigate('/screen')
  }

  const leaveViewer = async () => {
    teardown()
    joinedRef.current = false
    await meetingsApi.leave(code).catch(() => undefined)
    navigate('/screen')
  }

  const fullscreen = () => videoRef.current?.requestFullscreen().catch(() => undefined)

  const fmt = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

  if (loadError || phase === 'error' || phase === 'ended') {
    return (
      <Card className="mx-auto mt-10 max-w-md text-center">
        <p className="text-sm font-semibold">
          {phase === 'ended' ? 'Session ended' : 'Could not open this session'}
        </p>
        <p className="mt-1 text-xs text-slate-400">
          {phase === 'ended' ? 'The sharer has stopped sharing.' : errorMsg || 'Check the code and try again.'}
        </p>
        <Button className="mt-4" onClick={() => navigate('/screen')}>Back to Screen</Button>
      </Card>
    )
  }

  return (
    <div className="flex h-full flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-base font-semibold">
            {isHost ? 'Sharing your screen' : `${session?.host.name ?? ''}'s screen`}
          </h1>
          <p className="flex items-center gap-2 text-xs text-slate-400">
            <span className="font-mono">{code}</span>
            {phase === 'live' ? <span className="text-emerald-600">{fmt(elapsed)}</span> : 'Connecting…'}
            {isHost && (
              <span className="flex items-center gap-1">
                <Users className="size-3" /> {viewers.length} watching
                {viewers.length > 0 && `: ${viewers.map((v) => v.name).join(', ')}`}
              </span>
            )}
          </p>
        </div>
        <Button
          size="sm"
          variant="secondary"
          onClick={() =>
            navigator.clipboard.writeText(screenLink(code)).then(
              () => {
                setCopied(true)
                setTimeout(() => setCopied(false), 2000)
              },
              () => prompt('Copy this link:', screenLink(code)),
            )
          }
        >
          <Copy className="size-3.5" /> {copied ? 'Copied ✓' : 'Copy link'}
        </Button>
      </div>

      <div className="relative flex-1 overflow-hidden rounded-xl bg-slate-950">
        {phase === 'starting' && (
          <div className="flex h-full items-center justify-center">
            <Spinner />
          </div>
        )}
        <video ref={videoRef} autoPlay playsInline muted={isHost} className="h-full w-full object-contain" />
        {isHost && paused && (
          <div className="absolute inset-0 flex items-center justify-center bg-black/70 text-sm text-white">
            Sharing paused — viewers see a frozen frame
          </div>
        )}
      </div>

      <div className="flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
        {isHost ? (
          <>
            <Button size="sm" variant="secondary" onClick={togglePause} title={paused ? 'Resume' : 'Pause sharing'}>
              {paused ? <Play className="size-4" /> : <Pause className="size-4" />} {paused ? 'Resume' : 'Pause'}
            </Button>
            <Button size="sm" variant="danger" onClick={stopSharing}>
              <MonitorOff className="size-4" /> Stop sharing
            </Button>
          </>
        ) : (
          <>
            <Button size="sm" variant="secondary" onClick={fullscreen} title="Fullscreen">
              <Expand className="size-4" /> Fullscreen
            </Button>
            <Button size="sm" variant="danger" onClick={leaveViewer}>
              <MonitorUp className="size-4" /> Stop viewing
            </Button>
          </>
        )}
      </div>
    </div>
  )
}
