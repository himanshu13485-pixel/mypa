import { useEffect, useRef, useState } from 'react'
import { Mic, MicOff, RefreshCw, Video, VideoOff } from 'lucide-react'
import { clsx } from 'clsx'
import {
  AUDIO_CONSTRAINTS, VIDEO_CONSTRAINTS, loadDeviceChoice, saveDeviceChoice,
  speakerSelectionSupported, useDevices, useMicLevel, type DeviceChoice,
} from '../lib/devices'
import { Button, Card, ErrorNote, Input, Label, Select } from './ui'

export interface LobbyResult {
  displayName: string
  passcode: string
  micOn: boolean
  camOn: boolean
  devices: DeviceChoice
}

/**
 * The room before the room. Zoom and Teams both make you check yourself
 * before anyone can see you, and it doubles as the place to fix a dead
 * microphone without a roomful of people watching you do it.
 */
export default function MeetingLobby({
  title,
  hostName,
  needsPasscode,
  defaultName,
  audioOnly,
  busy,
  error,
  onJoin,
  onCancel,
}: {
  title: string
  hostName?: string
  needsPasscode?: boolean
  defaultName: string
  audioOnly?: boolean
  busy?: boolean
  error?: string | null
  onJoin: (result: LobbyResult) => void
  onCancel: () => void
}) {
  const stored = loadDeviceChoice()
  const [displayName, setDisplayName] = useState(defaultName)
  const [passcode, setPasscode] = useState('')
  const [micOn, setMicOn] = useState(true)
  const [camOn, setCamOn] = useState(!audioOnly)
  const [choice, setChoice] = useState<DeviceChoice>(stored)
  const [preview, setPreview] = useState<MediaStream | null>(null)
  const [mediaError, setMediaError] = useState<string | null>(null)

  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const { cameras, mics, speakers, refresh } = useDevices()
  const level = useMicLevel(preview)

  // (Re)open the preview whenever the chosen device changes. Labels only
  // appear after the first successful getUserMedia, hence the refresh().
  useEffect(() => {
    let cancelled = false

    const open = async () => {
      setMediaError(null)
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          audio: { ...AUDIO_CONSTRAINTS, ...(choice.micId ? { deviceId: { exact: choice.micId } } : {}) },
          video: audioOnly
            ? false
            : { ...VIDEO_CONSTRAINTS, ...(choice.cameraId ? { deviceId: { exact: choice.cameraId } } : {}) },
        })
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        streamRef.current?.getTracks().forEach((t) => t.stop())
        streamRef.current = stream
        setPreview(stream)
        if (videoRef.current) videoRef.current.srcObject = stream
        refresh()
      } catch (err) {
        if (cancelled) return
        setMediaError(
          /notallowed|permission|denied/i.test(String(err))
            ? 'Camera and microphone are blocked. Allow them in your browser, then reload.'
            : 'Camera or microphone is busy — close any other app or tab using it.',
        )
      }
    }

    void open()
    return () => {
      cancelled = true
    }
  }, [choice.cameraId, choice.micId, audioOnly, refresh])

  // Release the preview when we leave the lobby, either way.
  useEffect(() => () => streamRef.current?.getTracks().forEach((t) => t.stop()), [])

  useEffect(() => {
    streamRef.current?.getAudioTracks().forEach((t) => (t.enabled = micOn))
  }, [micOn, preview])

  useEffect(() => {
    streamRef.current?.getVideoTracks().forEach((t) => (t.enabled = camOn))
  }, [camOn, preview])

  const pick = (patch: DeviceChoice) => setChoice(saveDeviceChoice(patch))

  const join = () => {
    // Hand the preview back so the room doesn't reopen the camera and make
    // the user watch it blink off and on again.
    streamRef.current?.getTracks().forEach((t) => t.stop())
    onJoin({ displayName: displayName.trim() || defaultName, passcode, micOn, camOn, devices: choice })
  }

  return (
    <Card className="mx-auto mt-6 w-full max-w-3xl">
      <div className="grid gap-5 sm:grid-cols-[minmax(0,1fr)_240px]">
        {/* Preview */}
        <div>
          <div className="relative aspect-video overflow-hidden rounded-xl bg-slate-900">
            {!audioOnly && (
              <video
                ref={videoRef}
                autoPlay
                playsInline
                muted
                className={clsx('h-full w-full -scale-x-100 object-cover', !camOn && 'invisible')}
              />
            )}
            {(!camOn || audioOnly) && (
              <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 text-slate-300">
                <span className="flex size-16 items-center justify-center rounded-full bg-brand-700 text-2xl font-semibold text-white">
                  {(displayName || defaultName).charAt(0).toUpperCase()}
                </span>
                <span className="text-xs">{audioOnly ? 'Audio-only meeting' : 'Camera is off'}</span>
              </div>
            )}

            <div className="absolute inset-x-0 bottom-0 flex items-center justify-center gap-2 bg-gradient-to-t from-black/70 to-transparent p-2">
              <Button size="sm" variant={micOn ? 'secondary' : 'danger'} onClick={() => setMicOn((m) => !m)}>
                {micOn ? <Mic className="size-4" /> : <MicOff className="size-4" />}
              </Button>
              {!audioOnly && (
                <Button size="sm" variant={camOn ? 'secondary' : 'danger'} onClick={() => setCamOn((c) => !c)}>
                  {camOn ? <Video className="size-4" /> : <VideoOff className="size-4" />}
                </Button>
              )}
            </div>
          </div>

          {/* Mic meter — proof the microphone is actually picking you up. */}
          <div className="mt-2 flex items-center gap-2">
            <Mic className="size-3.5 shrink-0 text-slate-400" />
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
              <div
                className={clsx('h-full rounded-full transition-[width] duration-75', micOn ? 'bg-emerald-500' : 'bg-slate-400')}
                style={{ width: `${Math.round((micOn ? level : 0) * 100)}%` }}
              />
            </div>
            <span className="w-24 shrink-0 text-right text-[11px] text-slate-400">
              {micOn ? 'Say something' : 'Muted'}
            </span>
          </div>
        </div>

        {/* Settings */}
        <div className="space-y-3">
          <div>
            <h2 className="text-sm font-semibold">{title}</h2>
            {hostName && <p className="text-xs text-slate-400">Hosted by {hostName}</p>}
          </div>

          <ErrorNote message={mediaError ?? error ?? null} />

          <div>
            <Label>Your name in this meeting</Label>
            <Input value={displayName} onChange={(e) => setDisplayName(e.target.value)} maxLength={50} />
          </div>

          {needsPasscode && (
            <div>
              <Label>Passcode</Label>
              <Input
                value={passcode}
                onChange={(e) => setPasscode(e.target.value)}
                placeholder="From the invite"
                maxLength={12}
                autoComplete="off"
              />
            </div>
          )}

          {!audioOnly && cameras.length > 0 && (
            <div>
              <Label>Camera</Label>
              <Select value={choice.cameraId ?? ''} onChange={(e) => pick({ cameraId: e.target.value || undefined })}>
                <option value="">Default camera</option>
                {cameras.map((c) => (
                  <option key={c.deviceId} value={c.deviceId}>{c.label}</option>
                ))}
              </Select>
            </div>
          )}

          {mics.length > 0 && (
            <div>
              <Label>Microphone</Label>
              <Select value={choice.micId ?? ''} onChange={(e) => pick({ micId: e.target.value || undefined })}>
                <option value="">Default microphone</option>
                {mics.map((m) => (
                  <option key={m.deviceId} value={m.deviceId}>{m.label}</option>
                ))}
              </Select>
            </div>
          )}

          {speakerSelectionSupported() && speakers.length > 0 && (
            <div>
              <Label>Speaker</Label>
              <Select value={choice.speakerId ?? ''} onChange={(e) => pick({ speakerId: e.target.value || undefined })}>
                <option value="">Default speaker</option>
                {speakers.map((s) => (
                  <option key={s.deviceId} value={s.deviceId}>{s.label}</option>
                ))}
              </Select>
            </div>
          )}

          <div className="flex items-center gap-2 pt-1">
            <Button className="flex-1" onClick={join} disabled={busy || (needsPasscode && !passcode.trim())}>
              {busy ? 'Joining…' : 'Join now'}
            </Button>
            <Button variant="secondary" onClick={onCancel}>Cancel</Button>
          </div>
          <button
            className="flex items-center gap-1 text-[11px] text-slate-400 hover:text-brand-600"
            onClick={refresh}
          >
            <RefreshCw className="size-3" /> Rescan devices
          </button>
        </div>
      </div>
    </Card>
  )
}
